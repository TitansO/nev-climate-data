#!/bin/sh
echo "=== DEV ==="
psql -U nev_admin -d nev_climate_data -c "SELECT 'country' t, COUNT(*), MIN(id), MAX(id) FROM country UNION ALL SELECT 'sector', COUNT(*), MIN(id), MAX(id) FROM sector UNION ALL SELECT 'source', COUNT(*), MIN(id), MAX(id) FROM source;"
echo "=== PROD ==="
PGPASSWORD='pEkyfR1YL2M4PbbojSOIIsHF7fnDUgai' psql -h dpg-daar6d942hec73ecc0fg-a.oregon-postgres.render.com -p 5432 -U database_w4nb_user -d database_w4nb -c "SELECT 'country' t, COUNT(*), MIN(id), MAX(id) FROM country UNION ALL SELECT 'sector', COUNT(*), MIN(id), MAX(id) FROM sector UNION ALL SELECT 'source', COUNT(*), MIN(id), MAX(id) FROM source;"
