// The build script. The static preset runs it with the build env values and serves the dist folder that it writes.
// A static site has no server process, so the files can hold only what the build saw.
const fs = require('node:fs');

const build = process.env.VERIFY_BUILD || 'none';

fs.mkdirSync(`${__dirname}/dist`, { recursive: true });
fs.writeFileSync(`${__dirname}/dist/verify.json`, JSON.stringify({ fixture: 'site', build }));
fs.writeFileSync(`${__dirname}/dist/index.html`, `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>opencloud verify: site</title>
</head>
<body>
    <h1>opencloud verify: site</h1>
    <p>Build ${build}</p>
</body>
</html>
`);
