<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\SessionCookie;
use App\Support\SessionGuard;

/**
 * Customer product reviews.
 *
 * Reviews are held for moderation (admin Banners & reviews screen) before they appear or count
 * towards the rating. "Verified purchase" is decided here, never by the browser: it is set only
 * when the reviewer's email (or signed-in account) has a paid order containing the product.
 */
class ReviewController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * POST /api/v1/products/{idOrSlug}/reviews
     * {author_name, email, rating (1-5), title?, comment}
     */
    public function create(array $params): void
    {
        RateLimiter::attempt('review_submit', RequestContext::clientIp(), 5, 3600, 'Too many reviews from this connection. Please try again later.');

        $key = (string) ($params['idOrSlug'] ?? '');
        $product = ctype_digit($key)
            ? $this->db->fetchOne("SELECT `id`, `name` FROM `products` WHERE `id` = ? AND `status` = 'published'", 'i', [(int) $key])
            : $this->db->fetchOne("SELECT `id`, `name` FROM `products` WHERE `slug` = ? AND `status` = 'published'", 's', [$key]);
        if ($product === null) {
            Response::error('Product not found.', 404, 'NOT_FOUND');
        }

        $body = JsonBodyMiddleware::getBody();
        $session = SessionGuard::resolve(SessionCookie::SCOPE_CUSTOMER);
        $user = $session['user'] ?? null;

        $name = trim((string) ($body['author_name'] ?? ($user['full_name'] ?? '')));
        $email = strtolower(trim((string) ($user['email'] ?? $body['email'] ?? '')));
        $rating = (int) ($body['rating'] ?? 0);
        $title = trim((string) ($body['title'] ?? ''));
        $comment = trim((string) ($body['comment'] ?? ''));

        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['author_name'] = 'Enter your name (80 characters at most).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter the email you ordered with.';
        }
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choose a rating from 1 to 5 stars.';
        }
        if (mb_strlen($title) > 120) {
            $errors['title'] = 'Keep the title under 120 characters.';
        }
        if (mb_strlen($comment) < 10 || mb_strlen($comment) > 2000) {
            $errors['comment'] = 'Write between 10 and 2,000 characters.';
        }
        if ($errors !== []) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }

        $productId = (int) $product['id'];
        // One review per account, or per typed email for signed-out reviewers. A signed-out
        // review that used someone's email never blocks that person once they sign in.
        $duplicate = $user
            ? $this->db->fetchOne(
                "SELECT `id` FROM `reviews` WHERE `product_id` = ? AND `user_id` = ? AND `status` <> 'rejected' LIMIT 1",
                'ii',
                [$productId, (int) $user['id']]
            )
            : $this->db->fetchOne(
                "SELECT `id` FROM `reviews` WHERE `product_id` = ? AND `user_id` IS NULL AND LOWER(`author_email`) = ? AND `status` <> 'rejected' LIMIT 1",
                'is',
                [$productId, $email]
            );
        if ($duplicate !== null) {
            Response::error('You have already reviewed this product. Thank you!', 409, 'ALREADY_REVIEWED');
        }

        // Verified purchase: a paid (not refunded) order with this product that is provably the
        // reviewer's: placed on their account, or under their email once that email is confirmed.
        // A typed email proves nothing (anyone can type a buyer's address), so signed-out reviews
        // are never marked verified.
        $verified = false;
        if ($user) {
            $confirmedEmail = !empty($user['email_verified_at']) ? strtolower((string) $user['email']) : '';
            $verified = $this->db->fetchOne(
                "SELECT 1 AS ok
                 FROM `orders` o
                 JOIN `order_items` oi ON oi.order_id = o.id AND oi.product_id = ?
                 WHERE o.payment_status IN ('successful', 'partially_refunded')
                   AND o.order_status NOT IN ('cancelled', 'refunded')
                   AND (o.user_id = ? OR (? <> '' AND LOWER(o.guest_email) = ?))
                 LIMIT 1",
                'iiss',
                [$productId, (int) $user['id'], $confirmedEmail, $confirmedEmail]
            ) !== null;
        }

        $id = (int) $this->db->insert(
            "INSERT INTO `reviews` (`product_id`, `user_id`, `author_name`, `author_email`, `rating`, `title`, `comment`, `status`, `is_verified_purchase`)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
            'iississi',
            [$productId, $user ? (int) $user['id'] : null, $name, $email, $rating, $title !== '' ? $title : null, $comment, $verified ? 1 : 0]
        );

        Response::success(
            ['id' => $id, 'status' => 'pending', 'verified_purchase' => $verified],
            'Thanks! Your review will appear once the store has checked it.',
            201
        );
    }
}
