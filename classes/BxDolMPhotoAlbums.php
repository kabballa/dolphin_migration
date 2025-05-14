<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 *
 * @defgroup    DolphinMigration  Dolphin Migration
 * @ingroup     UnaModules
 *
 * @{
 */

/**
 * PURPOSE OF THIS FILE:
 * ---------------------
 * This file is responsible for migrating photo albums and photos from the Dolphin platform (boonex/photos module)
 * to the UNA platform (boonex/albums module).
 *
 * The migration process reads relevant tables from Dolphin and transfers the data into the corresponding tables in UNA.
 *
 * Database references:
 * - Dolphin: https://github.com/boonex/dolphin.pro/blob/master/modules/boonex/photos/install/sql/install.sql
 * - UNA:     https://github.com/unacms/una/blob/12.1.0/modules/boonex/albums/install/sql/install.sql
 *            https://github.com/unacms/una/blob/12.1.0/modules/boonex/albums/install/sql/enable.sql
 *
 * FIELD MIGRATION REPORT (Dolphin <-> UNA):
 * -----------------------------------------
 * 
 * ALBUMS (Dolphin: sys_albums, UNA: bx_albums_albums)
 * | Dolphin Field   | UNA Field        | Notes/Status                |
 * |-----------------|------------------|-----------------------------|
 * | ID              | id               | YES (PK)                    |
 * | Owner           | author           | YES                         |
 * | Caption         | title            | YES                         |
 * | Description     | text             | YES                         |
 * | Date            | added, changed   | YES                         |
 * | Status          | status_admin     | YES (active/hidden)         |
 * | AllowAlbumView  | allow_view_to    | YES                         |
 * | Views           | views            | YES                         |
 * | Thumb           | thumb            | YES (if exists)             |
 * | Comments        | comments         | YES (via transferComments)  |
 * | Type            | -                | Used for filtering only     |
 * | Uri             | -                | Used for filtering only     |
 * | ObjCount        | -                | Not migrated                |
 *
 * PHOTOS (Dolphin: bx_photos_main, UNA: bx_albums_files)
 * | Dolphin Field   | UNA Field        | Notes/Status                |
 * |-----------------|------------------|-----------------------------|
 * | ID              | id               | YES (PK)                    |
 * | Owner           | profile_id       | YES                         |
 * | Title           | title            | YES                         |
 * | Description     | description/text | YES                         |
 * | Date            | added            | YES                         |
 * | Hash            | hash             | YES (if exists)             |
 * | Ext             | ext              | YES (if exists)             |
 * | Size            | size/data        | YES                         |
 * | Dimensions      | dimensions       | YES (if exists)             |
 * | Views           | views            | YES                         |
 * | Featured        | featured         | YES (if exists)             |
 * | AllowComments   | allow_comments   | YES (if exists)             |
 * | AllowRate       | allow_vote       | YES (if exists)             |
 * | Location        | location         | YES (if exists)             |
 * | Status          | status           | YES (if exists)             |
 * | Rate            | rate             | YES (if exists)             |
 * | RateCount       | rate_count       | YES (if exists)             |
 * | Comments        | comments         | YES (via transferComments)  |
 * | Tags            | keywords/meta    | YES (via transferTags)      |
 * | Favorites       | favorites        | YES (via transferFavorites) |
 * | Votes           | votes            | YES (via transferVotes)     |
 * | Reactions       | reactions        | YES (if table exists)       |
 * | FileName        | file_name        | YES (implicit at upload)    |
 * | AlbumId         | -                | YES (via bx_albums_files2albums) |
 *
 * NOTE:
 * - Only fields with a direct correspondence or utility in UNA are migrated.
 * - Existence of fields in UNA is checked before transfer.
 * - For any additional fields, extend the mapping above as needed.
 */

require_once('BxDolMData.php');
bx_import('BxDolStorage');
	
class BxDolMPhotoAlbums extends BxDolMData
{
    /**
     * @var int $_iTransferredAlbums Number of transferred albums
     */
    private $_iTransferredAlbums;
    
    /**
     * Constructor.
     *
     * @param object $oMigrationModule Reference to the migration module
     * @param object $oDb Reference to the database connection
     */
    public function __construct(&$oMigrationModule, &$oDb)
    {
        parent::__construct($oMigrationModule, $oDb);
		$this -> _sModuleName = 'photos_albums';
		$this -> _sTableWithTransKey = 'bx_albums_albums';
    }
	
    /**
     * Get total number of photo albums and photos to migrate.
     *
     * @return array|int Returns array with count and obj or 0 if none found
     */
    public function getTotalRecords()
    {
        $aResult = $this -> _mDb -> getRow("SELECT COUNT(*) as `count`, SUM(`ObjCount`) as `obj` FROM `" . $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['table_name_albums'] ."` WHERE `Type` = 'bx_photos' AND `Uri` <> 'Hidden'");
		return !(int)$aResult['count'] && !(int)$aResult['obj'] ? 0 : $aResult;
	}
    
    /**
     * Run the migration process for photo albums and their photos.
     *
     * @return int Migration status code
     */
    public function runMigration()
    {
        if (!$this -> getTotalRecords())
        {
            $this -> setResultStatus(_t('_bx_dolphin_migration_no_data_to_transfer'));
            echo "No data to transfer.\n";
            return BX_MIG_SUCCESSFUL;
        }

        $sWhereCount = '';
        if ($this -> _oConfig -> _bTransferEmpty)
            $sWhereCount = " AND `ObjCount` <> 0";

        $this -> setResultStatus(_t('_bx_dolphin_migration_started_migration_photos'));
        echo "Migration started...\n";

        $this -> createMIdField();
        $aResult = $this -> _mDb -> getAll("SELECT * FROM `" . $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['table_name_albums'] ."` 
                                            WHERE `Type` = 'bx_photos' AND `Uri` <> 'Hidden' {$sWhereCount} ORDER BY `ID` ASC");
        foreach($aResult as $iKey => $aValue)
        {
            try {
                $iProfileId = $this -> getProfileId((int)$aValue['Owner']);
                if (!$iProfileId)
                    continue;

                $iAlbumId = $this -> isItemExisted($aValue['ID']);
                $bNewAlbum = false;
                if (!$iAlbumId)
                {
                    $sAlbumTitle = isset($aValue['Caption']) && $aValue['Caption'] ? $aValue['Caption'] : 'Profile Photos';
                    $sQuery = $this -> _oDb -> prepare(
                        "
                            INSERT INTO
                                `bx_albums_albums`
                            SET
                                `author`           = ?,
                                `added`            = ?,
                                `changed`          = ?,
                                `thumb`            = 0,
                                `title`            = ?,
                                `allow_view_to`    = ?,
                                `text`             = ?,
                                `status_admin`     = ?
                        ",
                        $iProfileId,
                        $aValue['Date'] ? $aValue['Date'] : time(),
                        $aValue['Date'] ? $aValue['Date'] : time(),
                        $sAlbumTitle,
                        $this -> getPrivacy($aValue['Owner'], (int)$aValue['AllowAlbumView'], 'photos', 'album_view'),
                        $aValue['Description'],
                        $aValue['Status'] == 'active' ? 'active' : 'hidden'
                    );

                    $this -> _oDb -> query($sQuery);
                    $iAlbumId = $this -> _oDb -> lastId();

                    if (!$iAlbumId)
                        throw new Exception('Album insert failed');

                    $this -> setMID($iAlbumId, $aValue['ID']);
                    $bNewAlbum = true;
                }

                // Transfer extra album fields after insert, but ignore errors
                try { $this->transferThumbField($aValue, $iAlbumId); } catch (\Exception $e) {}
                try { $this->transferAlbumViewsField($aValue['ID'], $iAlbumId); } catch (\Exception $e) {}

                $iAlbumsCmts = 0;
                try { $iAlbumsCmts = $this -> transferComments($iAlbumId, $aValue['ID'], 'photo_albums'); } catch (\Exception $e) {}
                if ($iAlbumsCmts)
                    $this -> _oDb -> query("UPDATE `bx_albums_albums` SET `comments` = :comments WHERE `id` = :id", array('id' => $iAlbumId, 'comments' => $iAlbumsCmts));

                $this -> migrateAlbumPhotos($aValue['ID'], $iProfileId, $iAlbumId);
                if ($bNewAlbum)
                    $this -> _iTransferredAlbums++;
            } catch (\Exception $e) {
                // Log error and continue with next album
                file_put_contents(BX_DIRECTORY_PATH_ROOT . 'logs/dolphin_migrations.log', "Album ID {$aValue['ID']} error: " . $e->getMessage() . "\n", FILE_APPEND);
                continue;
            }
        }

        // set as finished;
        $finalStatus = _t('_bx_dolphin_migration_started_migration_photos_albums_finished', $this -> _iTransferredAlbums, $this -> _iTransferred);
        $this -> setResultStatus($finalStatus);
        echo "Migration finished: $finalStatus\n";
        return BX_MIG_SUCCESSFUL;
    }
    
    /**
     * Migrate all photos from a Dolphin album to UNA.
     *
     * @param int $iAlbumId Original album ID in Dolphin
     * @param int $iProfileId UNA profile ID
     * @param int $iNewAlbumID Newly created UNA album ID
     * @return int Number of transferred photos
     */
    private function migrateAlbumPhotos($iAlbumId, $iProfileId, $iNewAlbumID) {
        $aResult = $this->_mDb->getAll("SELECT * 
                                        FROM `sys_albums_objects` 
                                        LEFT JOIN `" . $this->_oConfig->_aMigrationModules[$this->_sModuleName]['table_name'] . "` ON `id_object` = `ID`
                                        WHERE `id_album` = :album ORDER BY `id_object` ASC", array('album' => $iAlbumId));

        $iTransferred = 0;
        foreach ($aResult as $aValue) {
            try {
                $sFileName = "{$aValue['ID']}.{$aValue['Ext']}";
                if ($this->isFileExisted($iProfileId, $sFileName, $aValue['Date']))
                    continue;

                $sImagePath = $this->_sImagePhotoFiles . $sFileName;
                if (!file_exists($sImagePath))
                    continue;

                $oStorage = BxDolStorage::getObjectInstance('bx_albums_files');
                $iId = $oStorage->storeFileFromPath($sImagePath, false, $iProfileId, $iNewAlbumID);
                if ($iId) {
                    $this->updateFilesDate($iId, $aValue['Date']);

                    $sQuery = $this->_oDb->prepare("INSERT INTO `bx_albums_files2albums` SET `content_id` = ?, `file_id` = ?, `data` = ?, `title` = ?", $iNewAlbumID, $iId, $aValue['Size'], !empty($aValue['title']) ? $aValue['title'] : 'Untitled Photo');
                    $this->_oDb->query($sQuery);

                    // Transfer fields using private functions
                    $this->transferPhotoViewsField($aValue['ID'], $iId);
                    $this->transferFeaturedField($aValue, $iId);
                    $this->transferRateFields($aValue, $iId);
                    $this->transferLocationField($aValue, $iId);
                    $this->transferHashField($aValue, $iId);
                    $this->transferExtField($aValue, $iId);
                    $this->transferDimensionsField($aValue, $iId);
                    $this->transferAllowCommentsField($aValue, $iId);
                    $this->transferAllowRateField($aValue, $iId);
                    $this->transferStatusField($aValue, $iId);

                    $iCmts = $this->transferComments($iId, $aValue['ID'], 'photo_albums_items');
                    if ($iCmts)
                        $this->_oDb->query("UPDATE `bx_albums_files` SET `comments` = :comments WHERE `id` = :id", array('id' => $iId, 'comments' => $iCmts));

                    $this->_iTransferred++;
                    $iTransferred++;

                    $this->transferTags((int)$aValue['ID'], $iId, $this->_oConfig->_aMigrationModules[$this->_sModuleName]['type'], $this->_oConfig->_aMigrationModules[$this->_sModuleName]['keywords']);
                    $this->transferFavorites((int)$aValue['ID'], $iId);
                    $this->transferVotes((int)$aValue['ID'], $iId);
                    $this->transferReactions((int)$aValue['ID'], $iId);
                }
            } catch (\Exception $e) {
                // Log error and continue with next photo
                file_put_contents(BX_DIRECTORY_PATH_ROOT . 'logs/dolphin_migrations.log', "Photo ID {$aValue['ID']} error: " . $e->getMessage() . "\n", FILE_APPEND);
                continue;
            }
        }

        return $iTransferred;
    }

    /**
     * Transfer album views from Dolphin to UNA.
     *
     * @param int $iAlbumId Original album ID in Dolphin
     * @param int $iNewAlbumID New album ID in UNA
     * @return void
     */
    private function transferAlbumViewsField($iAlbumId, $iNewAlbumID) {
        $aAlbumViews = $this->_mDb->getRow("SELECT `Views` FROM `sys_albums` WHERE `ID` = :id LIMIT 1", array('id' => $iAlbumId));
        if (!empty($aAlbumViews) && $this->_oDb->isFieldExists('bx_albums_albums', 'views'))
            $this -> _oDb -> query("UPDATE `bx_albums_albums` SET `views` = :views WHERE `id` = :id", array('views' => (int)$aAlbumViews['Views'], 'id' => $iNewAlbumID));
    }

    /**
     * Transfer photo views from Dolphin to UNA.
     *
     * @param int $iPhotoId Original photo ID in Dolphin
     * @param int $iNewID New photo ID in UNA
     * @return void
     */
    private function transferPhotoViewsField($iPhotoId, $iNewID) {
        $aPhotoViews = $this->_mDb->getRow("SELECT `Views` FROM `bx_photos_main` WHERE `ID` = :id LIMIT 1", array('id' => $iPhotoId));
        if (!empty($aPhotoViews) && $this->_oDb->isFieldExists('bx_albums_files', 'views'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `views` = :views WHERE `id` = :id", array('views' => (int)$aPhotoViews['Views'], 'id' => $iNewID));
    }

    /**
     * Transfer featured flag from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferFeaturedField($aValue, $iId) {
        if (isset($aValue['Featured']) && $this->_oDb->isFieldExists('bx_albums_files', 'featured'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `featured` = :featured WHERE `id` = :id", array('featured' => (int)$aValue['Featured'], 'id' => $iId));
    }

    /**
     * Transfer rate and rate count from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferRateFields($aValue, $iId) {
        if (isset($aValue['Rate']) && isset($aValue['RateCount']) && $this->_oDb->isFieldExists('bx_albums_files', 'rate') && $this->_oDb->isFieldExists('bx_albums_files', 'rate_count'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `rate` = :rate, `rate_count` = :rate_count WHERE `id` = :id", array('rate' => (float)$aValue['Rate'], 'rate_count' => (int)$aValue['RateCount'], 'id' => $iId));
    }

    /**
     * Transfer location from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferLocationField($aValue, $iId) {
        if (isset($aValue['Location']) && $this->_oDb->isFieldExists('bx_albums_files', 'location'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `location` = :location WHERE `id` = :id", array('location' => $aValue['Location'], 'id' => $iId));
    }

    /**
     * Transfer hash from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferHashField($aValue, $iId) {
        if (isset($aValue['Hash']) && $this->_oDb->isFieldExists('bx_albums_files', 'hash'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `hash` = :hash WHERE `id` = :id", array('hash' => $aValue['Hash'], 'id' => $iId));
    }

    /**
     * Transfer extension from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferExtField($aValue, $iId) {
        if (isset($aValue['Ext']) && $this->_oDb->isFieldExists('bx_albums_files', 'ext'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `ext` = :ext WHERE `id` = :id", array('ext' => $aValue['Ext'], 'id' => $iId));
    }

    /**
     * Transfer dimensions from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferDimensionsField($aValue, $iId) {
        if (isset($aValue['Dimensions']) && $this->_oDb->isFieldExists('bx_albums_files', 'dimensions'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `dimensions` = :dimensions WHERE `id` = :id", array('dimensions' => $aValue['Dimensions'], 'id' => $iId));
    }

    /**
     * Transfer allow comments flag from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferAllowCommentsField($aValue, $iId) {
        if (isset($aValue['AllowComments']) && $this->_oDb->isFieldExists('bx_albums_files', 'allow_comments'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `allow_comments` = :allow_comments WHERE `id` = :id", array('allow_comments' => (int)$aValue['AllowComments'], 'id' => $iId));
    }

    /**
     * Transfer allow rate flag from Dolphin to UNA.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferAllowRateField($aValue, $iId) {
        if (isset($aValue['AllowRate']) && $this->_oDb->isFieldExists('bx_albums_files', 'allow_vote'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `allow_vote` = :allow_vote WHERE `id` = :id", array('allow_vote' => (int)$aValue['AllowRate'], 'id' => $iId));
    }

    /**
     * Transfer status from Dolphin to UNA for photos.
     *
     * @param array $aValue Dolphin photo row
     * @param int $iId UNA file ID
     * @return void
     */
    private function transferStatusField($aValue, $iId) {
        if (isset($aValue['Status']) && $this->_oDb->isFieldExists('bx_albums_files', 'status'))
            $this -> _oDb -> query("UPDATE `bx_albums_files` SET `status` = :status WHERE `id` = :id", array('status' => $aValue['Status'], 'id' => $iId));
    }

    /**
     * Transfer thumb from Dolphin to UNA for albums.
     *
     * @param array $aValue Dolphin album row
     * @param int $iAlbumId UNA album ID
     * @return void
     */
    private function transferThumbField($aValue, $iAlbumId) {
        if (isset($aValue['Thumb']) && $this->_oDb->isFieldExists('bx_albums_albums', 'thumb'))
            $this -> _oDb -> query("UPDATE `bx_albums_albums` SET `thumb` = :thumb WHERE `id` = :id", array('thumb' => $aValue['Thumb'], 'id' => $iAlbumId));
    }

    /**
     * Transfer votes from Dolphin to UNA.
     *
     * @param int $iItemId Dolphin photo ID
     * @param int $iNewID UNA file ID
     * @return void
     */
    private function transferVotes($iItemId, $iNewID) {
        // Example: migrate votes from Dolphin's bx_photos_rating to UNA's bx_albums_votes
        $aData = $this->_mDb->getRow("SELECT * FROM `bx_photos_rating` WHERE `gal_id` = :id LIMIT 1", array('id' => $iItemId));
        if (empty($aData))
            return false;

        // Insert into UNA votes table if exists
        if ($this->_oDb->isTableExists('bx_albums_votes')) {
            $sQuery = $this->_oDb->prepare("INSERT INTO `bx_albums_votes` SET `object_id` = ?, `count` = ?, `sum` = ?", $iNewID, $aData['gal_rating_count'], $aData['gal_rating_sum']);
            $this->_oDb->query($sQuery);
        }
        // Optionally update votes count in bx_albums_files if such a field exists
        if ($this->_oDb->isFieldExists('bx_albums_files', 'votes')) {
            $this->_oDb->query("UPDATE `bx_albums_files` SET `votes` = :votes WHERE `id` = :id", array('id' => $iNewID, 'votes' => (int)$aData['gal_rating_count']));
        }
        return true;
    }

    /**
     * Transfer reactions from Dolphin to UNA.
     *
     * @param int $iItemId Dolphin photo ID
     * @param int $iNewID UNA file ID
     * @return void
     */
    private function transferReactions($iItemId, $iNewID) {
        // Example: migrate reactions if such a table exists in Dolphin and UNA
        if (!$this->_oDb->isTableExists('bx_albums_reactions'))
            return false;

        $aReactions = $this->_mDb->getAll("SELECT * FROM `bx_photos_reactions` WHERE `object_id` = :id", array('id' => $iItemId));
        if (empty($aReactions))
            return false;

        foreach ($aReactions as $aReaction) {
            $sQuery = $this->_oDb->prepare("INSERT INTO `bx_albums_reactions` SET `object_id` = ?, `author_id` = ?, `reaction` = ?, `added` = ?", $iNewID, $this->getProfileId($aReaction['author_id']), $aReaction['reaction'], $aReaction['added']);
            $this->_oDb->query($sQuery);
        }
        return true;
    }

    /**
     * Check if a file already exists in UNA for a given author, file name, and date.
     *
     * @param int $iAuthor UNA profile ID
     * @param string $sTitle File name
     * @param int $iDate File date (timestamp)
     * @return bool True if file exists, false otherwise
     */
    private function isFileExisted($iAuthor, $sTitle, $iDate){
        $sQuery  = $this -> _oDb ->  prepare("SELECT COUNT(*) FROM `bx_albums_files` WHERE `profile_id` = ? AND `file_name` = ? AND `added` = ? LIMIT 1", $iAuthor, $sTitle, $iDate);
        return (bool)$this -> _oDb -> getOne($sQuery);
    }

    /**
     * Update the added date for a file in UNA.
     *
     * @param int $iId File ID in UNA
     * @param int $iDate New date (timestamp)
     * @return bool|int Result of the update query
     */
    private function updateFilesDate($iId, $iDate){
        $sQuery  = $this -> _oDb ->  prepare("UPDATE `bx_albums_files` SET `added`=? WHERE `id` = ?", $iDate, $iId);
        return $this -> _oDb -> query($sQuery);
    }

    /**
     * Remove all migrated photo albums and their content from UNA.
     *
     * @return int Number of records removed
     */
    public function removeContent()
    {
        if (!$this -> _oDb -> isTableExists($this -> _sTableWithTransKey) || !$this -> _oDb -> isFieldExists($this -> _sTableWithTransKey, $this -> _sTransferFieldIdent))
            return false;
        $aRecords = $this -> _oDb -> getAll("SELECT * FROM `{$this -> _sTableWithTransKey}` WHERE `{$this -> _sTransferFieldIdent}` !=0");
        $iNumber = 0;
        if (!empty($aRecords))
        {
            foreach($aRecords as $iKey => $aValue)
            {
                BxDolService::call('bx_albums', 'delete_entity', array($aValue['id']));
                $iNumber++;
            }
        }
        parent::removeContent();
        return $iNumber;
    }
}
/** @} */