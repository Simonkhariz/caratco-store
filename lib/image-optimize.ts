/**
 * Prepare admin photo uploads in the browser before they are sent: resize to at most MAX_EDGE
 * pixels on the longest side and re-encode at high quality. The stored file is the master copy:
 * customers get copies resized and re-encoded for their screen by next/image (AVIF/WebP), so the
 * master is kept sharp (quality 0.92) rather than small. A 4–8 MB phone photo usually becomes
 * 0.4–1.5 MB.
 *
 * Re-encoding drops camera metadata (location, device) from the file, so the re-encoded copy is
 * used even when it is not smaller. WebP is preferred; browsers that cannot encode WebP (Safari)
 * get a JPEG instead (PNG for PNG sources, which may be transparent). Only if both fail, or the
 * copy would pass the server's 5 MB limit, the original goes up; the server strips its metadata.
 */

const MAX_EDGE = 2560;
const QUALITY = 0.92;
const SERVER_LIMIT_BYTES = 5 * 1024 * 1024;

function canvasToBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

async function decode(file: File): Promise<ImageBitmap | HTMLImageElement> {
  if (typeof createImageBitmap === 'function') {
    try {
      // Applies the photo's EXIF rotation, so portrait phone shots stay upright.
      return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
      // Fall through to <img> decoding.
    }
  }
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.decoding = 'async';
    img.src = url;
    await img.decode();
    return img;
  } finally {
    URL.revokeObjectURL(url);
  }
}

export async function optimizeImageForUpload(file: File): Promise<File> {
  if (typeof document === 'undefined' || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;

  try {
    const source = await decode(file);
    const width = 'naturalWidth' in source ? source.naturalWidth : source.width;
    const height = 'naturalHeight' in source ? source.naturalHeight : source.height;
    if (!width || !height) return file;

    const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const ctx = canvas.getContext('2d');
    if (!ctx) return file;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    if ('close' in source) source.close();

    // Some browsers cannot encode WebP and silently return PNG instead: then JPEG (or PNG for a
    // PNG source, which may be transparent), which every browser can encode.
    let blob = await canvasToBlob(canvas, 'image/webp', QUALITY);
    if (!blob || blob.type !== 'image/webp') {
      const fallback = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      blob = await canvasToBlob(canvas, fallback, QUALITY);
      if (!blob || blob.type !== fallback) return file;
    }
    if (blob.size > SERVER_LIMIT_BYTES) return file;

    const extension = blob.type === 'image/webp' ? 'webp' : blob.type === 'image/png' ? 'png' : 'jpg';
    const base = file.name.replace(/\.[^.]+$/, '') || 'photo';
    return new File([blob], `${base}.${extension}`, { type: blob.type, lastModified: Date.now() });
  } catch {
    return file;
  }
}
