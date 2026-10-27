// Reports what the opencloud verification matrix checks: env values, the build, the databases, and the cache.
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const mysql = require('mysql2/promise');
const pg = require('pg');
const { createClient } = require('redis');

// The generic env profile writes mysql:// and postgresql:// URLs.
const DRIVERS = { 'mysql:': 'mysql', 'postgres:': 'pgsql', 'postgresql:': 'pgsql' };

function env(key) {
    return process.env[key] || null;
}

async function connect(url) {
    const driver = DRIVERS[new URL(url).protocol];

    if (driver === 'mysql') {
        const connection = await mysql.createConnection({ uri: url, connectTimeout: 5000 });

        return { driver, id: 'INT AUTO_INCREMENT PRIMARY KEY', param: '?', query: async (sql, params) => (await connection.query(sql, params))[0], end: () => connection.end() };
    }

    if (driver === 'pgsql') {
        const client = new pg.Client({ connectionString: url, connectionTimeoutMillis: 5000 });
        await client.connect();

        return { driver, id: 'SERIAL PRIMARY KEY', param: '$1', query: async (sql, params) => (await client.query(sql, params)).rows, end: () => client.end() };
    }

    throw new Error(`${url.split(':')[0]}:// is not a mysql:// or postgresql:// URL.`);
}

async function database() {
    const url = env('DATABASE_URL');

    if (url === null) {
        return null;
    }

    let db;

    try {
        db = await connect(url);
        await db.query('INSERT INTO verify_hits (created_at) VALUES (CURRENT_TIMESTAMP)');
        const count = async (table) => Number((await db.query(`SELECT COUNT(*) AS n FROM ${table}`))[0].n);

        return { driver: db.driver, ok: true, hits: await count('verify_hits'), deploys: await count('verify_deploys') };
    } catch (error) {
        return { driver: DRIVERS[new URL(url).protocol] ?? null, ok: false, error: error.message };
    } finally {
        await db?.end();
    }
}

// Opencloud sets one <NAME>_DATABASE_URL value for each database that is not the default.
async function extra() {
    const found = {};

    for (const key of Object.keys(process.env).filter((key) => key.endsWith('_DATABASE_URL')).sort()) {
        const name = key.slice(0, -'_DATABASE_URL'.length).toLowerCase();
        let db;

        try {
            db = await connect(process.env[key]);
            await db.query('SELECT 1');
            found[name] = { driver: db.driver, ok: true };
        } catch (error) {
            found[name] = { driver: DRIVERS[new URL(process.env[key]).protocol] ?? null, ok: false, error: error.message };
        } finally {
            await db?.end();
        }
    }

    return found;
}

async function cache() {
    const url = env('REDIS_URL');

    if (url === null) {
        return null;
    }

    const client = createClient({ url, socket: { connectTimeout: 5000, reconnectStrategy: false } });
    client.on('error', () => {});

    try {
        await client.connect();

        return { driver: 'redis', ok: (await client.ping()) === 'PONG', hits: await client.incr('verify:hits') };
    } catch (error) {
        return { driver: 'redis', ok: false, error: error.message };
    } finally {
        client.destroy();
    }
}

async function report() {
    let build = null;

    try {
        build = fs.readFileSync(path.join(__dirname, 'build-marker'), 'utf8').trim() || null;
    } catch {}

    return {
        fixture: 'node',
        marker: env('VERIFY_MARKER'),
        build,
        secret_sha: env('VERIFY_SECRET') === null ? null : crypto.createHash('sha1').update(env('VERIFY_SECRET')).digest('hex'),
        db: await database(),
        cache: await cache(),
        extra: await extra(),
    };
}

module.exports = { connect, report };
