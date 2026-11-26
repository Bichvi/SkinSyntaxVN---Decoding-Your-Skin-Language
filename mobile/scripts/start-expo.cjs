const { spawn } = require('node:child_process');
const { networkInterfaces } = require('node:os');
const { join, dirname } = require('node:path');

function detectLanAddress() {
  const virtual = /loopback|docker|wsl|virtual|veth|vmware|vbox|hyper-v|vethernet|tailscale|vpn|tunnel|tap|tun|bridge|bluetooth|wireguard|wintun|^utun|^br-/i;
  const addresses = new Set();
  for (const [name, entries] of Object.entries(networkInterfaces())) {
    if (virtual.test(name)) continue;
    for (const entry of entries ?? []) {
      if (entry.family === 'IPv4' && !entry.internal && !entry.address.startsWith('169.254.')) {
        addresses.add(entry.address);
      }
    }
  }
  if (addresses.size !== 1) {
    throw new Error('Cannot select one LAN IPv4 address. Set SKINSYNTAX_DEV_API_URL for this run.');
  }
  return [...addresses][0];
}

function developmentUrl() {
  const port = process.env.SKINSYNTAX_API_PORT || '80';
  const url = new URL(process.env.SKINSYNTAX_DEV_API_URL || `http://${detectLanAddress()}:${port}`);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
    throw new Error('Use an HTTP(S) development API base URL without credentials, query or fragment.');
  }
  return url.href;
}

function start() {
  const [mode, ...args] = process.argv.slice(2);
  if (!['production', 'development'].includes(mode)) throw new Error('Invalid API mode.');
  const env = {
    ...process.env,
    EXPO_NO_DOTENV: '1',
    EXPO_PUBLIC_API_MODE: mode,
    EXPO_PUBLIC_API_BASE_URL: mode === 'development' ? developmentUrl() : '',
  };
  const cli = join(dirname(require.resolve('expo/package.json')), 'bin', 'cli');
  const connection = args.some(arg => ['--lan', '--localhost', '--tunnel'].includes(arg))
    ? []
    : [mode === 'production' ? '--tunnel' : '--lan'];
  const expo = spawn(process.execPath, [cli, 'start', ...connection, ...args], { env, stdio: 'inherit' });
  expo.on('error', error => { console.error(error.message); process.exitCode = 1; });
  expo.on('exit', (code, signal) => { process.exitCode = code ?? (signal ? 130 : 1); });
}

try {
  start();
} catch (error) {
  console.error(error.message === 'Invalid URL' ? 'Invalid development API URL.' : error.message);
  process.exitCode = 1;
}
