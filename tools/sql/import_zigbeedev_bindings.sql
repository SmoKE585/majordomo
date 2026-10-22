-- Run in the UPDATED MajorDoMo database after opening /panel/zigbee.html.
-- Disable the old cycle_zigbeedev (including autostart) before running;
-- restart cycle_mqtt afterwards.
-- Reads the legacy tables in this database, not a dump file.
-- Does not copy old readings, execute handlers, or publish device commands.
-- Existing matching bindings and their rules remain unchanged; conflicts abort.

DELIMITER $$
DROP PROCEDURE IF EXISTS mdj_import_zigbeedev_bindings$$
CREATE PROCEDURE mdj_import_zigbeedev_bindings()
BEGIN
    DECLARE imported_devices INT DEFAULT 0;
    DECLARE imported_bindings INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
    DROP TEMPORARY TABLE IF EXISTS mdj_zigbee_import_devices;
    DROP TEMPORARY TABLE IF EXISTS mdj_zigbee_import_properties;

    IF EXISTS (
        SELECT 1 FROM zigbeeproperties p LEFT JOIN zigbeedevices d ON d.ID=p.DEVICE_ID
        WHERE p.LINKED_OBJECT<>'' AND (p.LINKED_PROPERTY<>'' OR p.LINKED_METHOD<>'')
          AND (d.ID IS NULL OR d.IS_HUB<>0)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Legacy binding has no device or refers to a coordinator';
    END IF;

    -- Zigbee2MQTT addresses each device as '<bridge>/<friendly_name>', and on
    -- these installations the friendly name is the IEEE address itself
    -- ('Zigbee7550/0x00158D000237212E'). The old module's TITLE is only its own
    -- display name and never appears in a topic. FULL_PATH is kept in the old
    -- table but goes stale after a rename, so it is used only for the root.
    CREATE TEMPORARY TABLE mdj_zigbee_import_devices ENGINE=InnoDB AS
    SELECT d.ID AS LEGACY_ID,
        CONVERT(SUBSTRING_INDEX(d.FULL_PATH,'/',1) USING utf8mb4) COLLATE utf8mb4_bin AS BASE_TOPIC,
        CONVERT(LOWER(d.IEEEADDR) USING utf8mb4) COLLATE utf8mb4_bin AS IEEE_ADDRESS,
        CONVERT(d.IEEEADDR USING utf8mb4) COLLATE utf8mb4_bin AS FRIENDLY_NAME,
        CONVERT(COALESCE(NULLIF(d.TITLE,''),d.IEEEADDR) USING utf8mb4) COLLATE utf8mb4_bin AS TITLE,
        d.MODEL,d.VENDOR,d.DESCRIPTION,d.FULL_PATH,d.UPDATED,
        CAST(NULL AS UNSIGNED) AS BRIDGE_ID, CAST(NULL AS UNSIGNED) AS DEVICE_ID
    FROM zigbeedevices d
    WHERE d.IS_HUB=0 AND EXISTS (
        SELECT 1 FROM zigbeeproperties p WHERE p.DEVICE_ID=d.ID AND p.LINKED_OBJECT<>''
          AND (p.LINKED_PROPERTY<>'' OR p.LINKED_METHOD<>'')
    );

    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_devices
        WHERE LOCATE('/',FULL_PATH)=0 OR BASE_TOPIC='' OR CHAR_LENGTH(BASE_TOPIC)>190
          OR BASE_TOPIC REGEXP '[+#]' OR FRIENDLY_NAME='' OR CHAR_LENGTH(FRIENDLY_NAME)>190
          OR FRIENDLY_NAME REGEXP '[+#]' OR IEEE_ADDRESS NOT REGEXP '^0x[0-9a-f]{16}$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid legacy MQTT path or IEEE address';
    END IF;
    -- MySQL cannot open the same TEMPORARY table twice in one statement
    -- (ER_CANT_REOPEN_TABLE, #1137), so each check stays in its own IF.
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_devices GROUP BY BASE_TOPIC,IEEE_ADDRESS HAVING COUNT(*)>1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ambiguous legacy device identity (duplicate IEEE address)';
    END IF;
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_devices s JOIN zigbee_bridges b
        ON LEFT(CONCAT(s.BASE_TOPIC,'/'),CHAR_LENGTH(b.BASE_TOPIC)+1)=CONCAT(b.BASE_TOPIC,'/')
        OR LEFT(CONCAT(b.BASE_TOPIC,'/'),CHAR_LENGTH(s.BASE_TOPIC)+1)=CONCAT(s.BASE_TOPIC,'/')
        WHERE s.BASE_TOPIC<>b.BASE_TOPIC) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Legacy root overlaps an existing Zigbee root';
    END IF;

    INSERT INTO zigbee_bridges (TITLE,BASE_TOPIC,ENABLED)
    SELECT DISTINCT s.BASE_TOPIC,s.BASE_TOPIC,1 FROM mdj_zigbee_import_devices s
    WHERE NOT EXISTS (SELECT 1 FROM zigbee_bridges b WHERE b.BASE_TOPIC=s.BASE_TOPIC);
    UPDATE mdj_zigbee_import_devices s JOIN zigbee_bridges b ON b.BASE_TOPIC=s.BASE_TOPIC
    SET s.BRIDGE_ID=b.ID;

    -- An early state message may have created an IEEE-less stub named after the
    -- device topic before discovery completed. Drop such empty stubs so the
    -- import adopts the real record instead of leaving a duplicate behind.
    DELETE d FROM zigbee_devices d JOIN mdj_zigbee_import_devices s
        ON d.BRIDGE_ID=s.BRIDGE_ID AND d.IEEE_ADDRESS IS NULL
       AND BINARY d.FRIENDLY_NAME=BINARY s.FRIENDLY_NAME
    WHERE NOT EXISTS (SELECT 1 FROM zigbee_properties p WHERE p.DEVICE_ID=d.ID);

    INSERT INTO zigbee_devices (BRIDGE_ID,IEEE_ADDRESS,FRIENDLY_NAME,TITLE,MODEL,VENDOR,DESCRIPTION,STATUS)
    SELECT s.BRIDGE_ID,s.IEEE_ADDRESS,s.FRIENDLY_NAME,s.TITLE,s.MODEL,s.VENDOR,s.DESCRIPTION,'added'
    FROM mdj_zigbee_import_devices s WHERE NOT EXISTS (
        SELECT 1 FROM zigbee_devices d WHERE d.BRIDGE_ID=s.BRIDGE_ID
          AND (LOWER(d.IEEE_ADDRESS)=s.IEEE_ADDRESS OR BINARY d.FRIENDLY_NAME=BINARY s.FRIENDLY_NAME)
    );
    UPDATE zigbee_devices d JOIN mdj_zigbee_import_devices s
        ON d.BRIDGE_ID=s.BRIDGE_ID AND BINARY d.FRIENDLY_NAME=BINARY s.FRIENDLY_NAME
    LEFT JOIN zigbee_devices known ON known.BRIDGE_ID=s.BRIDGE_ID AND LOWER(known.IEEE_ADDRESS)=s.IEEE_ADDRESS
    SET d.IEEE_ADDRESS=s.IEEE_ADDRESS WHERE d.IEEE_ADDRESS IS NULL AND known.ID IS NULL;
    UPDATE zigbee_devices d
    JOIN mdj_zigbee_import_devices s
        ON d.BRIDGE_ID=s.BRIDGE_ID AND LOWER(d.IEEE_ADDRESS)=s.IEEE_ADDRESS
    LEFT JOIN zigbee_devices taken
        ON taken.BRIDGE_ID=d.BRIDGE_ID AND taken.ID<>d.ID AND BINARY taken.FRIENDLY_NAME=BINARY s.FRIENDLY_NAME
    SET d.IEEE_ADDRESS=s.IEEE_ADDRESS,d.FRIENDLY_NAME=s.FRIENDLY_NAME,d.TITLE=s.TITLE,
        d.MODEL=COALESCE(NULLIF(d.MODEL,''),s.MODEL),d.VENDOR=COALESCE(NULLIF(d.VENDOR,''),s.VENDOR),
        d.DESCRIPTION=COALESCE(NULLIF(d.DESCRIPTION,''),s.DESCRIPTION)
    WHERE d.METADATA IS NULL AND taken.ID IS NULL;  -- discovery owns known devices
    UPDATE mdj_zigbee_import_devices s JOIN zigbee_devices d
        ON d.BRIDGE_ID=s.BRIDGE_ID AND LOWER(d.IEEE_ADDRESS)=s.IEEE_ADDRESS SET s.DEVICE_ID=d.ID;
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_devices WHERE DEVICE_ID IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Existing friendly name belongs to a different IEEE address';
    END IF;
    UPDATE zigbee_devices d JOIN mdj_zigbee_import_devices s ON s.DEVICE_ID=d.ID SET d.STATUS='added';

    CREATE TEMPORARY TABLE mdj_zigbee_import_properties ENGINE=InnoDB AS
    SELECT p.ID AS LEGACY_ID,s.DEVICE_ID,
        CONVERT(p.TITLE USING utf8mb4) COLLATE utf8mb4_bin AS PATH,
        p.LINKED_OBJECT,p.LINKED_PROPERTY,p.LINKED_METHOD,p.READ_ONLY,p.VALUE,
        CAST(NULL AS UNSIGNED) AS PROPERTY_ID
    FROM zigbeeproperties p JOIN mdj_zigbee_import_devices s ON s.LEGACY_ID=p.DEVICE_ID
    WHERE p.LINKED_OBJECT<>'' AND (p.LINKED_PROPERTY<>'' OR p.LINKED_METHOD<>'');
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_properties GROUP BY DEVICE_ID,PATH HAVING COUNT(*)>1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Duplicate legacy property bindings';
    END IF;
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_properties s LEFT JOIN objects o ON o.TITLE=s.LINKED_OBJECT
        WHERE o.ID IS NULL AND s.LINKED_OBJECT<>'AllScripts') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A linked MajorDoMo object is missing';
    END IF;
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_properties s WHERE s.LINKED_PROPERTY<>'' AND NOT EXISTS (
        SELECT 1 FROM pvalues v JOIN objects o ON o.ID=v.OBJECT_ID
        WHERE o.TITLE=s.LINKED_OBJECT AND v.PROPERTY_NAME=CONCAT(s.LINKED_OBJECT,'.',s.LINKED_PROPERTY)
    )) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A linked property has no pvalues registration';
    END IF;

    INSERT INTO zigbee_properties (DEVICE_ID,PATH,LABEL)
    SELECT s.DEVICE_ID,s.PATH,s.PATH FROM mdj_zigbee_import_properties s
    WHERE NOT EXISTS (SELECT 1 FROM zigbee_properties p WHERE p.DEVICE_ID=s.DEVICE_ID AND p.PATH=s.PATH);
    UPDATE mdj_zigbee_import_properties s JOIN zigbee_properties p
        ON p.DEVICE_ID=s.DEVICE_ID AND p.PATH=s.PATH SET s.PROPERTY_ID=p.ID;
    -- Preserve the old configured READ_ONLY permission until real discovery
    -- supplies exposes. Never overwrite device metadata already discovered.
    UPDATE zigbee_properties p JOIN mdj_zigbee_import_properties s ON s.PROPERTY_ID=p.ID
    SET p.EXPOSE=JSON_OBJECT('property',s.PATH,'label',s.PATH,'access',
        IF(s.READ_ONLY<>'1' AND s.LINKED_PROPERTY<>'',3,1),'source','zigbeedev-import')
    WHERE p.EXPOSE IS NULL;
    IF EXISTS (SELECT 1 FROM mdj_zigbee_import_properties s JOIN zigbee_bindings b ON b.PROPERTY_ID=s.PROPERTY_ID
        WHERE BINARY b.LINKED_OBJECT<>BINARY s.LINKED_OBJECT
           OR BINARY b.LINKED_PROPERTY<>BINARY s.LINKED_PROPERTY
           OR BINARY b.LINKED_METHOD<>BINARY s.LINKED_METHOD) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Existing Zigbee binding conflicts with the legacy binding';
    END IF;

    INSERT INTO zigbee_bindings (PROPERTY_ID,LINKED_OBJECT,LINKED_PROPERTY,LINKED_METHOD,
        ENABLED,WRITE_ENABLED,EVENT_MODE,INCOMING,OUTGOING)
    SELECT s.PROPERTY_ID,s.LINKED_OBJECT,s.LINKED_PROPERTY,s.LINKED_METHOD,1,
        IF(s.READ_ONLY<>'1' AND s.LINKED_PROPERTY<>'',1,0),'always',
        '{"mode":"map","case_sensitive":false,"pairs":[{"from":true,"to":1},{"from":false,"to":0},{"from":"true","to":1},{"from":"false","to":0},{"from":"on","to":1},{"from":"off","to":0},{"from":"yes","to":1},{"from":"no","to":0},{"from":"close","to":1},{"from":"open","to":0}]}',
        CASE
            WHEN BINARY s.VALUE IN (BINARY 'true',BINARY 'false') THEN '{"mode":"map","pairs":[{"from":1,"to":true},{"from":0,"to":false}]}'
            WHEN BINARY s.VALUE IN (BINARY 'ON',BINARY 'OFF') THEN '{"mode":"map","pairs":[{"from":1,"to":"ON"},{"from":0,"to":"OFF"}]}'
            WHEN BINARY s.VALUE IN (BINARY 'CLOSE',BINARY 'OPEN') THEN '{"mode":"map","pairs":[{"from":1,"to":"CLOSE"},{"from":0,"to":"OPEN"}]}'
            ELSE '{"mode":"none"}'
        END
    FROM mdj_zigbee_import_properties s
    WHERE NOT EXISTS (SELECT 1 FROM zigbee_bindings b WHERE b.PROPERTY_ID=s.PROPERTY_ID);

    -- Subscribe object properties to the new module, preserving other subscribers.
    UPDATE pvalues v JOIN objects o ON o.ID=v.OBJECT_ID
    JOIN mdj_zigbee_import_properties s ON s.LINKED_OBJECT=o.TITLE
        AND v.PROPERTY_NAME=CONCAT(s.LINKED_OBJECT,'.',s.LINKED_PROPERTY) AND s.LINKED_PROPERTY<>''
    SET v.LINKED_MODULES=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM
        REPLACE(REPLACE(CONCAT(',',v.LINKED_MODULES,','),',zigbeedev,',','),',zigbee,',',')),''),'zigbee');

    SELECT COUNT(*) INTO imported_devices FROM mdj_zigbee_import_devices;
    SELECT COUNT(*) INTO imported_bindings FROM mdj_zigbee_import_properties;
    DROP TEMPORARY TABLE mdj_zigbee_import_properties;
    DROP TEMPORARY TABLE mdj_zigbee_import_devices;
    COMMIT;
    SELECT imported_devices AS devices,imported_bindings AS bindings;
END$$
CALL mdj_import_zigbeedev_bindings()$$
DROP PROCEDURE mdj_import_zigbeedev_bindings$$
DELIMITER ;
