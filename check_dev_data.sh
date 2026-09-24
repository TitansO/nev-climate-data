#!/bin/sh
psql -U nev_admin -d nev_climate_data -c "
SELECT 'project' AS t, COUNT(*) FROM project
UNION ALL SELECT 'emission', COUNT(*) FROM emission
UNION ALL SELECT 'funding', COUNT(*) FROM funding
UNION ALL SELECT 'opportunity', COUNT(*) FROM opportunity
UNION ALL SELECT 'ndc_target', COUNT(*) FROM ndc_target;
" > /tmp/dev_counts2.txt 2>&1
echo "exit=$?" >> /tmp/dev_counts2.txt
