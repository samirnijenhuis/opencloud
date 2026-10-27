const http = require('node:http');
const { report } = require('./verify');

const page = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>opencloud verify: node</title>
</head>
<body>
    <h1>opencloud verify: node</h1>
    <p><a href="/verify">/verify</a></p>
</body>
</html>
`;

http.createServer(async (request, response) => {
    if (request.url === '/verify') {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(await report()));

        return;
    }

    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end(page);
}).listen(Number(process.env.PORT || 3000));
