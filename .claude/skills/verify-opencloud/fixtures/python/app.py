from flask import Flask, jsonify

from verify import report

app = Flask(__name__)

PAGE = """<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>opencloud verify: python</title>
</head>
<body>
    <h1>opencloud verify: python</h1>
    <p><a href="/verify">/verify</a></p>
</body>
</html>
"""


@app.get('/')
def index():
    return PAGE


@app.get('/verify')
def verify():
    return jsonify(report())
