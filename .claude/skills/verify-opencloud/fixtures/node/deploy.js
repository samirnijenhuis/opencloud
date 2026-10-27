// Opencloud runs this as a deploy command before the new version gets traffic: node deploy.js <cell>
const { connect } = require('./verify');

async function main() {
    const cell = process.argv[2] ?? 'unknown';
    console.log(`deploy-marker-${cell}`);

    if (!process.env.DATABASE_URL) {
        return;
    }

    const db = await connect(process.env.DATABASE_URL);
    await db.query(`CREATE TABLE IF NOT EXISTS verify_hits (id ${db.id}, created_at TIMESTAMP NOT NULL)`);
    await db.query(`CREATE TABLE IF NOT EXISTS verify_deploys (id ${db.id}, cell VARCHAR(100) NOT NULL, created_at TIMESTAMP NOT NULL)`);
    await db.query(`INSERT INTO verify_deploys (cell, created_at) VALUES (${db.param}, CURRENT_TIMESTAMP)`, [cell]);
    console.log('The deploy command wrote one row to verify_deploys.');
    await db.end();
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
