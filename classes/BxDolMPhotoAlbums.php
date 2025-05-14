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
            return BX_MIG_SUCCESSFUL;
        }

        $sWhereCount = '';
        if ($this -> _oConfig -> _bTransferEmpty)
            $sWhereCount = " AND `ObjCount` <> 0";

        $this -> setResultStatus(_t('_bx_dolphin_migration_started_migration_photos'));

        $this -> createMIdField();
        $aResult = $this -> _mDb -> getAll("SELECT * FROM `" . $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['table_name_albums'] ."` 
                                            WHERE `Type` = 'bx_photos' AND `Uri` <> 'Hidden' {$sWhereCount} ORDER BY `ID` ASC");
        foreach($aResult as $iKey => $aValue)
        {           
            $iProfileId = $this -> getProfileId((int)$aValue['Owner']);
            if (!$iProfileId) 
                continue;

            $iAlbumId = $this -> isItemExisted($aValue['ID']);            
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
                    {
                        $this -> setResultStatus(_t('_bx_dolphin_migration_started_migration_photos_album_error'));
                        return BX_MIG_FAILED;
                    }

                $this -> setMID($iAlbumId, $aValue['ID']);                
            }

            $iAlbumsCmts = $this -> transferComments($iAlbumId, $aValue['ID'], 'photo_albums');
            if ($iAlbumsCmts)
                $this -> _oDb -> query("UPDATE `bx_albums_albums` SET `comments` = :comments WHERE `id` = :id", array('id' => $iAlbumId, 'comments' => $iAlbumsCmts));
            
            $this -> migrateAlbumPhotos($aValue['ID'], $iProfileId, $iAlbumId);    
            $this -> _iTransferredAlbums++;
       }        

        // set as finished;
        $this -> setResultStatus(_t('_bx_dolphin_migration_started_migration_photos_albums_finished', $this -> _iTransferredAlbums, $this -> _iTransferred));
        return BX_MIG_SUCCESSFUL;
    }

   /**
	* Migrates all photo albums and users photos
	* @param int $iAlbumId original albums id
	* @param int $iProfileId una profile ID
	* @param int $iNewAlbumID created una Album		
	* @return Integer
         */  
   private function migrateAlbumPhotos($iAlbumId, $iProfileId, $iNewAlbumID){
        $aResult = $this -> _mDb -> getAll("SELECT * 
                                                FROM  `sys_albums_objects` 
                                                LEFT JOIN `" . $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['table_name'] ."` ON `id_object` = `ID`
                                                WHERE  `id_album` = :album ORDER BY `id_object` ASC", array('album' => $iAlbumId));

        $iTransferred  = 0;
        foreach($aResult as $iKey => $aValue)
        { 
            $sFileName = "{$aValue['ID']}.{$aValue['Ext']}";
            if ($this -> isFileExisted($iProfileId, $sFileName, $aValue['Date']))
                continue;

            $sImagePath = $this -> _sImagePhotoFiles . $sFileName;
            if (file_exists($sImagePath))
            {
                $oStorage = BxDolStorage::getObjectInstance('bx_albums_files');
                $iId = $oStorage -> storeFileFromPath($sImagePath, false, $iProfileId, $iNewAlbumID);
                if ($iId)
                { 
                    $this -> updateFilesDate($iId, $aValue['Date']);

                    $sQuery = $this -> _oDb -> prepare("INSERT INTO `bx_albums_files2albums` SET `content_id` = ?, `file_id` = ?, `data` = ?, `title` = ?", $iNewAlbumID, $iId, $aValue['Size'], $aValue['Title']);
                    $this -> _oDb -> query($sQuery);

                    $iCmts = $this -> transferComments($iItemId = $this -> _oDb -> lastId(), $aValue['ID'], 'photo_albums_items');
                    if ($iCmts)
                        $this -> _oDb -> query("UPDATE `bx_albums_files2albums` SET `comments` = :comments WHERE `id` = :id", array('id' => $iItemId, 'comments' => $iCmts));

                    $this -> _iTransferred++;
                    $iTransferred++;

                    $this -> transferTags((int)$aValue['ID'], $iId, $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['type'], $this -> _oConfig -> _aMigrationModules[$this -> _sModuleName]['keywords']);
                    $this -> transferFavorites((int)$aValue['ID'], $iId);

                    // Additionally: transfer all relevant extra fields
                    $this -> transferAlbumViews((int)$aValue['ID'], $iId);
                    $this -> transferPhotoViews((int)$aValue['ID'], $iId);
                }
            }
        }   
              
      return $iTransferred;
   }

    /**
     * Transfers a photo's favorite record to the new albums favorites media track table.
     *
     * This method retrieves the favorite record for a given photo ID from the `bx_photos_favorites` table,
     * checks if the associated profile exists, and then inserts a new record into the `bx_albums_favorites_media_track` table
     * with the new media ID, author ID, and date.
     *
     * @param int $iPhotoId The ID of the photo whose favorite record is to be transferred.
     * @param int $iNewID The new media ID to associate with the favorite record.
     * @return bool|int Returns false if the transfer fails, or the result of the insert query on success.
     */
    private function transferFavorites($iPhotoId, $iNewID){
        $aData = $this->_mDb->getRow("SELECT * FROM `bx_photos_favorites` WHERE `ID`=:id LIMIT 1", array('id' => $iPhotoId));
        if (empty($aData))
            return false;

        $iProfileId = $this -> getProfileId((int)$aData['Profile']);
        if (!$iProfileId)
            return false;

        $sQuery = $this -> _oDb -> prepare("INSERT INTO `bx_albums_favorites_media_track` SET `object_id` = ?, `author_id` = ?, `date` = ?", $iNewID, $iProfileId, ($aData['Date'] ? $aData['Date'] : time()));
        return $this -> _oDb -> query($sQuery);
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
     * ---------------- Additionally: transfer all relevant extra fields start
     */

    /**
     * Transfer album views from Dolphin to UNA.
     *
     * @param int $iId Original album ID in Dolphin
     * @param int $iNewId New album ID in UNA
     * @return void
     */
    private function transferAlbumViews($iId, $iNewId)
    {
        // Fetch views from Dolphin's sys_albums table
        $aAlbum = $this->_mDb->getRow("SELECT `Views` FROM `sys_albums` WHERE `ID` = :id LIMIT 1", array('id' => $iId));
        if (!$aAlbum || !isset($aAlbum['Views']))
            return;

        $iViews = (int)$aAlbum['Views'];

        // Update the `views` field in the `bx_albums_albums` table in UNA only if field exists and IDs are valid
        if ($iNewId && $this->_oDb->isFieldExists('bx_albums_albums', 'views')) {
            $this->_oDb->query("UPDATE `bx_albums_albums` SET `views` = :views WHERE `id` = :id", array('views' => $iViews, 'id' => $iNewId));
        }
    }

    /**
     * Transfer photo views from Dolphin to UNA.
     *
     * @param int $iId Original photo ID in Dolphin
     * @param int $iNewId New photo ID in UNA
     * @return void
     */
    private function transferPhotoViews($iId, $iNewId)
    {
        // Fetch views from Dolphin's bx_photos_main table
        $aPhoto = $this->_mDb->getRow("SELECT `Views` FROM `bx_photos_main` WHERE `ID` = :id LIMIT 1", array('id' => $iId));
        $iViews = (!empty($aPhoto) && isset($aPhoto['Views'])) ? (int)$aPhoto['Views'] : 0;

        // Update the `views` field in the `bx_albums_files` table in UNA
        if ($this->_oDb->isFieldExists('bx_albums_files', 'views')) {
            $this->_oDb->query("UPDATE `bx_albums_files` SET `views` = :views WHERE `id` = :id", array('views' => $iViews, 'id' => $iNewId));
        }
    }

    /**
     * ---------------- Additionally: transfer all relevant extra fields finmished
     */

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