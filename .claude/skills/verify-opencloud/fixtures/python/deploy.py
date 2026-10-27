"""Opencloud runs this as a deploy command before the new version gets traffic: python deploy.py <cell>"""
import os
import sys

from verify import ID, connect, query

cell = sys.argv[1] if len(sys.argv) > 1 else 'unknown'
print(f'deploy-marker-{cell}', flush=True)

if os.environ.get('DATABASE_URL'):
    driver, connection = connect(os.environ['DATABASE_URL'])
    with connection:
        query(connection, f'CREATE TABLE IF NOT EXISTS verify_hits (id {ID[driver]}, created_at TIMESTAMP NOT NULL)')
        query(connection, f'CREATE TABLE IF NOT EXISTS verify_deploys (id {ID[driver]}, cell VARCHAR(100) NOT NULL, created_at TIMESTAMP NOT NULL)')
        query(connection, 'INSERT INTO verify_deploys (cell, created_at) VALUES (%s, CURRENT_TIMESTAMP)', (cell,))
    print('The deploy command wrote one row to verify_deploys.')
