#!/bin/bash
set -euo pipefail

app_pw_sql=${POSTGRES_APP_PASSWORD//\'/\'\'}
test_pw_sql=${POSTGRES_TEST_PASSWORD//\'/\'\'}

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
SELECT CASE WHEN EXISTS (SELECT FROM pg_roles WHERE rolname = 'medifind_dev')
    THEN 'SELECT 1'
    ELSE 'CREATE ROLE medifind_dev LOGIN PASSWORD ''${app_pw_sql}'' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT'
END AS stmt \gexec

SELECT CASE WHEN EXISTS (SELECT FROM pg_roles WHERE rolname = 'medifind_test')
    THEN 'SELECT 1'
    ELSE 'CREATE ROLE medifind_test LOGIN PASSWORD ''${test_pw_sql}'' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT'
END AS stmt \gexec

SELECT CASE WHEN EXISTS (SELECT FROM pg_database WHERE datname = 'medifind')
    THEN 'SELECT 1'
    ELSE 'CREATE DATABASE medifind OWNER medifind_dev'
END AS stmt \gexec

SELECT CASE WHEN EXISTS (SELECT FROM pg_database WHERE datname = 'medifind_testing')
    THEN 'SELECT 1'
    ELSE 'CREATE DATABASE medifind_testing OWNER medifind_test'
END AS stmt \gexec

REVOKE CONNECT ON DATABASE medifind FROM PUBLIC;
REVOKE CONNECT ON DATABASE medifind_testing FROM PUBLIC;
REVOKE CONNECT ON DATABASE medifind FROM medifind_test;
REVOKE CONNECT ON DATABASE medifind_testing FROM medifind_dev;
GRANT CONNECT ON DATABASE medifind TO medifind_dev;
GRANT CONNECT ON DATABASE medifind_testing TO medifind_test;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname medifind <<-EOSQL
CREATE EXTENSION IF NOT EXISTS postgis;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO medifind_dev;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname medifind_testing <<-EOSQL
CREATE EXTENSION IF NOT EXISTS postgis;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO medifind_test;
EOSQL
