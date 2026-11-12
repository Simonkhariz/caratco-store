// node deploy/cpanel/pack.mjs <folder> <out.zip>
// Zips a folder for a Linux host, with the permissions a host expects: files 644, folders 755.
// Zip tools on Windows mark everything 666/777, and many shared hosts refuse to run a PHP file that
// others may write to, answering every request with a 500 error. Only Node's own modules are used.
import { closeSync, lstatSync, openSync, readdirSync, readFileSync, writeSync } from 'node:fs';
import { join } from 'node:path';
import { deflateRawSync } from 'node:zlib';

const [root, out] = process.argv.slice(2);
if (!root || !out) {
  console.error('Usage: node pack.mjs <folder> <out.zip>');
  process.exit(2);
}

const crcTable = Int32Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c;
});
const crc32 = (buffer) => {
  let c = -1;
  for (const byte of buffer) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
};

// Files and folders, parents first, with forward slashes. A link would break on the host.
const entries = [];
const walk = (dir, prefix) => {
  for (const item of readdirSync(dir).sort()) {
    const path = join(dir, item);
    const stat = lstatSync(path);
    const name = prefix + item;
    if (stat.isSymbolicLink()) throw new Error(`${path} is a link, which would break on the host`);
    if (stat.isDirectory()) {
      entries.push({ name: `${name}/`, dir: true, stat });
      walk(path, `${name}/`);
    } else {
      entries.push({ name, dir: false, path, stat });
    }
  }
};
walk(root, '');
if (entries.length >= 0xffff) throw new Error('Too many files for this zip format');

const dosTime = (date) => ({
  time: (date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1),
  date: (Math.max(date.getFullYear() - 1980, 0) << 9) | ((date.getMonth() + 1) << 5) | date.getDate(),
});

const UTF8_NAMES = 0x0800;
const MADE_ON_UNIX = (3 << 8) | 20;
const fd = openSync(out, 'w');
let offset = 0;
const write = (buffer) => {
  writeSync(fd, buffer);
  offset += buffer.length;
};
const central = [];

for (const entry of entries) {
  const name = Buffer.from(entry.name, 'utf8');
  const raw = entry.dir ? Buffer.alloc(0) : readFileSync(entry.path);
  const packed = entry.dir ? raw : deflateRawSync(raw, { level: 9 });
  const stored = packed.length >= raw.length;
  const data = stored ? raw : packed;
  const method = stored ? 0 : 8;
  const crc = crc32(raw);
  const { time, date } = dosTime(entry.stat.mtime);

  const local = Buffer.alloc(30);
  local.writeUInt32LE(0x04034b50, 0);
  local.writeUInt16LE(20, 4);
  local.writeUInt16LE(UTF8_NAMES, 6);
  local.writeUInt16LE(method, 8);
  local.writeUInt16LE(time, 10);
  local.writeUInt16LE(date, 12);
  local.writeUInt32LE(crc, 14);
  local.writeUInt32LE(data.length, 18);
  local.writeUInt32LE(raw.length, 22);
  local.writeUInt16LE(name.length, 26);
  const at = offset;
  write(local);
  write(name);
  write(data);

  const header = Buffer.alloc(46);
  header.writeUInt32LE(0x02014b50, 0);
  header.writeUInt16LE(MADE_ON_UNIX, 4);
  header.writeUInt16LE(20, 6);
  header.writeUInt16LE(UTF8_NAMES, 8);
  header.writeUInt16LE(method, 10);
  header.writeUInt16LE(time, 12);
  header.writeUInt16LE(date, 14);
  header.writeUInt32LE(crc, 16);
  header.writeUInt32LE(data.length, 20);
  header.writeUInt32LE(raw.length, 24);
  header.writeUInt16LE(name.length, 28);
  // Unix type and permissions in the top half; the lowest bit range marks a folder for other tools.
  const mode = entry.dir ? 0o040755 : 0o100644;
  header.writeUInt32LE(((mode * 0x10000) + (entry.dir ? 0x10 : 0)) >>> 0, 38);
  header.writeUInt32LE(at, 42);
  central.push(header, name);
}

const centralStart = offset;
for (const piece of central) write(piece);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(entries.length, 8);
end.writeUInt16LE(entries.length, 10);
end.writeUInt32LE(offset - centralStart, 12);
end.writeUInt32LE(centralStart, 16);
write(end);
closeSync(fd);
