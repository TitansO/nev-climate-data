#!/bin/sh
PGPASSWORD='pEkyfR1YL2M4PbbojSOIIsHF7fnDUgai' psql -h dpg-daar6d942hec73ecc0fg-a.oregon-postgres.render.com -p 5432 -U database_w4nb_user -d database_w4nb -c "
SELECT 'project' AS table_name, COUNT(*) FROM project
UNION ALL SELECT 'emission', COUNT(*) FROM emission
UNION ALL SELECT 'funding', COUNT(*) FROM funding
UNION ALL SELECT 'opportunity', COUNT(*) FROM opportunity
UNION ALL SELECT 'ndc_target', COUNT(*) FROM ndc_target;
" > /tmp/prod_counts.txt 2>&1
echo "exit=$?" >> /tmp/prod_counts.txt
