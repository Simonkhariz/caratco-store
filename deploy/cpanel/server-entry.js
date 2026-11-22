// Startup file for the storefront on shared hosting (cPanel "Setup Node.js App" runs server.js).
// deploy/cpanel/build.ps1 renames Next.js's own standalone server to next-server.js and puts this
// file in its place.
//
// Why: the API limits sign-in tries, orders and lookups per visitor address. It reads the visitor
// from X-Forwarded-For, right to left, trusting only the storefront's address. Next.js passes on
// whatever X-Forwarded-For a visitor sends and adds the real address only when there is none, so a
// visitor could make up a new address for every request and never hit a limit (found in the
// 22 Nov 2026 penetration test). Here the address that really connected is added to the end of
// the list, as a proxy should do: whatever a visitor writes stays to its left and is ignored.
'use strict';
/* eslint-disable @typescript-eslint/no-require-imports -- the host runs this file directly with Node (CommonJS) */

const http = require('http');

const emit = http.Server.prototype.emit;
http.Server.prototype.emit = function patchedEmit(event, req, ...rest) {
  if (event === 'request' && req && req.headers && req.socket) {
    const real = req.socket.remoteAddress;
    if (real) {
      const sent = req.headers['x-forwarded-for'];
      const chain = (Array.isArray(sent) ? sent.join(',') : sent || '').trim();
      req.headers['x-forwarded-for'] = chain ? `${chain}, ${real}` : real;
    }
  }
  return emit.call(this, event, req, ...rest);
};

require('./next-server.js');
