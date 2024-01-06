#!/usr/bin/env bash

#
# Copyright (c) 2016 Hubzilla
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.
#

# Exit if anything fails
set -e

#
# Initialize some defaults if they're not set by the environment
#
: ${DB_ROOT_USER:=postgres}
: ${DB_TEST_USER:=test_user}
: ${DB_TEST_DB:=hubzilla_test_db}

echo "Creating test db for PostgreSQL..."

if [[ "$POSTGRESQL_VERSION" == "10" ]]; then
	echo "Using PostgreSQL in Docker container, need to use TCP"
	export PROTO="-h localhost"
fi

# Print out some PostgreSQL information
psql --version
# Why does this hang further execution of the job?
psql $PROTO -U $DB_ROOT_USER -c "SELECT VERSION();"

# Create Hubzilla database
psql $PROTO -U $DB_ROOT_USER -v ON_ERROR_STOP=1 <<-EOSQL
	DROP DATABASE IF EXISTS $DB_TEST_DB;
	DROP USER IF EXISTS $DB_TEST_USER;
    CREATE USER $DB_TEST_USER WITH PASSWORD 'hubzilla';
    CREATE DATABASE $DB_TEST_DB WITH OWNER $DB_TEST_USER;
EOSQL

export PGPASSWORD=hubzilla

# Import table structure
echo "Importing schema..."
psql $PROTO -U $DB_TEST_USER -v ON_ERROR_STOP=1 $DB_TEST_DB < ./install/schema_postgres.sql

# Show databases and tables
psql $PROTO -U $DB_TEST_USER -l
psql $PROTO -U $DB_TEST_USER -d $DB_TEST_DB -c "\dt;"
