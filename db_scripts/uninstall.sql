-- The order is load-bearing: MySQL and MariaDB ignore the CASCADE keyword on DROP TABLE, so a child
-- table has to go before the table it points at. Only PostgreSQL would forgive a different order.
DROP TABLE IF EXISTS %PREFIX%_req_activity_files CASCADE;
DROP TABLE IF EXISTS %PREFIX%_req_activity_credits CASCADE;
DROP TABLE IF EXISTS %PREFIX%_req_requirement_role_rules CASCADE;
DROP TABLE IF EXISTS %PREFIX%_req_activities CASCADE;
DROP TABLE IF EXISTS %PREFIX%_req_requirement_types CASCADE;
DROP TABLE IF EXISTS %PREFIX%_req_providers CASCADE;
