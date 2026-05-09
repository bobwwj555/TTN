-- TTN Node Config System Migration
UPDATE sites SET site_api_key = LOWER(REPLACE(UUID(),'-','')) WHERE site_api_key IS NULL;
SELECT id, name, site_api_key FROM sites ORDER BY id;
UPDATE systems SET access_code = '107.2' WHERE site_id = 3 AND (access_code = '118.8' OR access_code IS NULL);
DELETE FROM sys_interfaces WHERE system_id IN (SELECT id FROM systems WHERE site_id IN (1, 3, 6, 8));
INSERT INTO sys_interfaces (system_id, label, url, interface_type, is_public, sort_order) VALUES
((SELECT id FROM systems WHERE site_id=8 ORDER BY sort_order LIMIT 1), 'AllScan',     'https://tn.w4bww.net/allscan',        'allscan',  1, 0),
((SELECT id FROM systems WHERE site_id=8 ORDER BY sort_order LIMIT 1), 'Supermon',    'https://tn.w4bww.net/supermon',       'supermon', 1, 1),
((SELECT id FROM systems WHERE site_id=8 ORDER BY sort_order LIMIT 1), 'Allmon3',     'https://tn.w4bww.net/allmon3',        'allmon3',  1, 2),
((SELECT id FROM systems WHERE site_id=8 ORDER BY sort_order LIMIT 1), 'QRZ · W4BWW', 'https://www.qrz.com/db/W4BWW',       'custom',   1, 3),
((SELECT id FROM systems WHERE site_id=8 ORDER BY sort_order LIMIT 1), 'TTN Network', 'https://ttn.radio',                   'custom',   1, 4),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'AllScan',     'https://hub.ttn.radio/allscan',       'allscan',  1, 0),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'Supermon',    'https://hub.ttn.radio/supermon',      'supermon', 1, 1),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'Supermon-NG', 'https://hub.ttn.radio/supermon-ng',   'supermon', 1, 2),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'Allmon3',     'https://hub.ttn.radio/allmon3',       'allmon3',  1, 3),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'W4BWW TN-HUB','https://tn.w4bww.net',               'custom',   1, 4),
((SELECT id FROM systems WHERE site_id=1 ORDER BY sort_order LIMIT 1), 'TTN Network', 'https://ttn.radio',                   'custom',   1, 5),
((SELECT id FROM systems WHERE site_id=6 ORDER BY sort_order LIMIT 1), 'Supermon',    'https://chatt.ttn.radio/supermon',    'supermon', 1, 0),
((SELECT id FROM systems WHERE site_id=6 ORDER BY sort_order LIMIT 1), 'Allmon3',     'https://chatt.ttn.radio/allmon3',     'allmon3',  1, 1),
((SELECT id FROM systems WHERE site_id=6 ORDER BY sort_order LIMIT 1), 'TTN Network', 'https://ttn.radio',                   'custom',   1, 2),
((SELECT id FROM systems WHERE site_id=3 ORDER BY sort_order LIMIT 1), 'Supermon-NG', 'https://cra.ttn.radio/supermon-ng',   'supermon', 1, 0),
((SELECT id FROM systems WHERE site_id=3 ORDER BY sort_order LIMIT 1), 'Allmon3',     'https://cra.ttn.radio/allmon3',       'allmon3',  1, 1),
((SELECT id FROM systems WHERE site_id=3 ORDER BY sort_order LIMIT 1), 'TTN Network', 'https://ttn.radio',                   'custom',   1, 2);
SELECT s.name AS site, si.sort_order, si.label, si.interface_type, si.url
FROM sys_interfaces si
JOIN systems sys ON sys.id=si.system_id
JOIN sites s ON s.id=sys.site_id
WHERE s.id IN (1,3,6,8)
ORDER BY s.id, si.sort_order;
