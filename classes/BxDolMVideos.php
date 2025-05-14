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

require_once('BxDolMData.php');
bx_import('BxDolStorage');

/**
 * PURPOSE OF THIS FILE:
 * ---------------------
 * This file is responsible for migrating videos from the Dolphin platform (boonex/videos module)
 * to the UNA platform (boonex/videos module).
 *
 * The migration process reads relevant tables from Dolphin and transfers the data into the corresponding tables in UNA.
 *
 * Database references:
 * - Dolphin: https://github.com/boonex/dolphin.pro/blob/master/modules/boonex/videos/install/sql/install.sql
 * - UNA:     https://github.com/unacms/una/blob/12.1.0/modules/boonex/videos/install/sql/install.sql
 *            https://github.com/unacms/una/blob/12.1.0/modules/boonex/videos/install/sql/enable.sql
 *
 * FIELD MIGRATION REPORT (Dolphin <-> UNA):
 * -----------------------------------------
 *
 * VIDEOS (Dolphin: RayVideoFiles or similar, UNA: bx_videos_entries, sys_files)
 * | Dolphin Field (RayVideoFiles/sys_albums) | UNA Field (bx_videos_entries) | Notes/Status                                  |
 * |------------------------------------------|-------------------------------|-----------------------------------------------|
 * | ID (Video)                               | id (via MId)                  | YES (Primary Key)                             |
 * | Owner (Video)                            | author                        | YES (via getProfileId)                        |
 * | Title (Video)                            | title                         | YES                                           |
 * | Description (Video)                      | text                          | YES                                           |
 * | Date (Video)                             | added, changed                | YES                                           |
 * | Categories (Video)                       | cat                           | YES (via transferCategory)                    |
 * | Tags (Video)                             | (handled by transferTags)     | YES                                           |
 * | Views (Video)                            | views                         | YES (via transferViews)                       |
 * | Status (Video)                           | status                        | YES (approved -> active, else hidden)         |
 * | Status (Album)                           | status_admin                  | YES (active -> active, else hidden)           |
 * | AllowAlbumView (Album)                   | allow_view_to                 | YES (via getPrivacy)                          |
 * | Rate, RateCount (Video)                  | (handled by transferSVotes)   | YES (to bx_videos_svotes & svotes field)      |
 * | CommentsCount (Video)                    | comments                      | YES (via transferComments)                    |
 * | Featured (Video)                         | featured                      | TODO: Implement if needed                     |
 * | Time (duration)                          | duration                      | TODO: If both fields exist                    |
 * | Ext (Video file extension)               | video (sys_files.ext)         | YES (file stored in UNA sys_files)            |
 * | (File Path)                              | (Handled by BxDolStorage)     | YES                                           |
 *
 * FAVORITES (Dolphin: bx_videos_favorites, UNA: bx_videos_favorites_track)
 * | Dolphin Field   | UNA Field        | Notes/Status                |
 * |-----------------|------------------|-----------------------------|
 * | ID (Video ID)   | object_id        | YES                         |
 * | Profile         | author_id        | YES (via getProfileId)      |
 * | Date            | date             | YES                         |
 *
 * VOTES/RATINGS (Dolphin: bx_videos_rating, UNA: bx_videos_svotes)
 * | Dolphin Field      | UNA Field (bx_videos_svotes) | Notes/Status                |
 * |--------------------|------------------------------|-----------------------------|
 * | gal_id (Video ID)  | object_id                    | YES                         |
 * | gal_rating_count   | count                        | YES                         |
 * | gal_rating_sum     | sum                          | YES                         |
 * |                    | bx_videos_entries.svotes     | YES (updated with count)    |
 *
 * COMMENTS (Dolphin: bx_videos_cmts or bx_videos_cmts_albums, UNA: bx_videos_cmts)
 * | Dolphin Field   | UNA Field        | Notes/Status                |
 * |-----------------|------------------|-----------------------------|
 * | cmt_id          | cmt_id           | YES (new ID generated)      |
 * | cmt_parent_id   | cmt_parent_id    | YES (mapped to new parent)  |
 * | cmt_object_id   | cmt_object_id    | YES (mapped to new UNA ID)  |
 * | cmt_author_id   | cmt_author_id    | YES (via getProfileId)      |
 * | cmt_text        | cmt_text         | YES                         |
 * | cmt_time        | cmt_time         | YES (timestamp converted)   |
 * | cmt_replies     | cmt_replies      | YES                         |
 * | cmt_rate        | rate (sys_cmts_ids) | YES                      |
 *
 * NOTE:
 * - 'table_name' in migration config should point to Dolphin's main video files table (e.g., RayVideoFiles).
 * - 'table_name_videos' in migration config should point to Dolphin's sys_albums table (filtered for video type).
 * - File extension is assumed to be in an 'Ext' column in the Dolphin video table.
 * - The 'duration' and 'featured' field migration for videos is marked as TODO.
 */

class BxDolMVideos extends BxDolMData
{
    private $_sVideoFilesPath;

    public function __construct(&$oMigrationModule, &$oDb)
    {
        parent::__construct($oMigrationModule, $oDb);
        $this->_sModuleName = 'videos';
        $this->_sTableWithTransKey = 'bx_videos_entries';
        $this->_sVideoFilesPath = $this->_oDb->getExtraParam('root') . 'flash' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . "video" . DIRECTORY_SEPARATOR . "files" . DIRECTORY_SEPARATOR;
    }

    public function getTotalRecords()
    {
        return $this->_mDb->getOne("SELECT SUM(`ObjCount`) as `obj` 
            FROM `" . $this->_oConfig->_aMigrationModules[$this->_sModuleName]['table_name_videos'] . "` 
            WHERE `Type` = 'bx_videos' AND `Uri` <> 'Hidden'");
    }

    public function runMigration()
    {
        if (!$this->getTotalRecords()) {
            $this->setResultStatus(_t('_bx_dolphin_migration_no_data_to_transfer'));
            return BX_MIG_SUCCESSFUL;
        }

        $sWhereCount = '';
        if ($this->_oConfig->_bTransferEmpty)
            $sWhereCount = " AND `ObjCount` <> 0";

        $this->setResultStatus(_t('_bx_dolphin_migration_started_migration_videos'));

        $this->createMIdField();
        $aResult = $this->_mDb->getAll("SELECT * FROM `" . $this->_oConfig->_aMigrationModules[$this->_sModuleName]['table_name_videos'] . "` 
            WHERE `Type` = 'bx_videos' AND `Uri` <> 'Hidden' {$sWhereCount} ORDER BY `ID` ASC");

        foreach ($aResult as $iKey => $aValue) {
            $iProfileId = $this->getProfileId((int)$aValue['Owner']);
            if (!$iProfileId)
                continue;

            $this->migrateVideo($aValue['ID'], $iProfileId);
        }

        $this->setResultStatus(_t('_bx_dolphin_migration_started_migration_videos_finished', $this->_iTransferred));
        return BX_MIG_SUCCESSFUL;
    }

    /**
     * Migrates all videos from a Dolphin album to UNA.
     * @param int $iAlbumId Dolphin album ID
     * @param int $iProfileId UNA profile ID
     * @return int Number of transferred videos
     */
    private function migrateVideo($iAlbumId, $iProfileId)
    {
        $aResult = $this->_mDb->getAll("SELECT  `m`.*, `a`.`AllowAlbumView`, `a`.`Status` as `admin_status`, `m`.`Status` as `status`
            FROM  `sys_albums_objects` as `o`
            LEFT JOIN `sys_albums` as `a` ON `o`.`id_album` = `a`.`ID`
            LEFT JOIN `" . $this->_oConfig->_aMigrationModules[$this->_sModuleName]['table_name'] . "` as `m` ON `o`.`id_object` = `m`.`ID`
            WHERE  `o`.`id_album` = :album ORDER BY `o`.`id_object` ASC", array('album' => $iAlbumId));

        $iTransferred = 0;
        foreach ($aResult as $iKey => $aValue) {
            $iVideoId = $this->isItemExisted($aValue['ID']);
            if ($iVideoId)
                continue;

            // Respect original field names as in Dolphin export
            $sVideoTitle = !empty($aValue['Title']) ? $aValue['Title'] : 'Untitled Video';
            $sVideoText = !empty($aValue['Description']) ? $aValue['Description'] : 'No description available';

            $sQuery = $this->_oDb->prepare(
                "
                INSERT INTO
                    `bx_videos_entries`
                SET
                    `author`         = ?,
                    `added`          = ?,
                    `changed`        = ?,
                    `video`          = 0,
                    `title`          = ?,
                    `allow_view_to`  = ?,
                    `text`           = ?,
                    `status_admin`   = ?,
                    `status`         = ?,
                    `cat`            = ?
                ",
                $iProfileId,
                $aValue['Date'] ? $aValue['Date'] : time(),
                $aValue['Date'] ? $aValue['Date'] : time(),
                $sVideoTitle,
                $this->getPrivacy($aValue['Owner'], (int)$aValue['AllowAlbumView'], 'videos', 'album_view'),
                $sVideoText,
                $aValue['admin_status'] == 'active' ? 'active' : 'hidden',
                $aValue['status'] == 'approved' ? 'active' : 'hidden',
                $this->transferCategory($aValue['Categories'], 'bx_videos', 'bx_videos_cats')
            );

            $this->_oDb->query($sQuery);
            if ($iVideoId = $this->_oDb->lastId())
                $this->setMID($iVideoId, $aValue['ID']);
            else
                continue;

            $sFileName = "{$aValue['ID']}.m4v";
            $sVideoPath = $this->_sVideoFilesPath . $sFileName;
            if (file_exists($sVideoPath)) {
                $oStorage = BxDolStorage::getObjectInstance('bx_videos_videos');
                $iId = $oStorage->storeFileFromPath($sVideoPath, false, $iProfileId, $iVideoId);
                if ($iId) {
                    $this->_iTransferred++;
                    $this->transferTags((int)$aValue['ID'], $iId, $this->_oConfig->_aMigrationModules[$this->_sModuleName]['type'], $this->_oConfig->_aMigrationModules[$this->_sModuleName]['keywords']);
                    $this->transferFavorites((int)$aValue['ID'], $iId);
                    $this->transferSVotes((int)$aValue['ID'], $iId);
                    $this->transferViews((int)$aValue['ID'], $iVideoId);

                    $this->_oDb->query("UPDATE `bx_videos_entries` SET `comments` = :comments, `video`=:video WHERE `id` = :id", array('id' => $iVideoId, 'video' => $iId, 'comments' => $this->transferComments($iVideoId, $aValue['ID'], 'videos')));
                }
            }
        }

        return $iTransferred;
    }

    /**
     * Transfers the view count of a video.
     * @param int $iItemId original video ID
     * @param int $iNewID new video ID in UNA
     * @return boolean
     */
    private function transferViews($iItemId, $iNewID)
    {
        $aData = $this->_mDb->getRow("SELECT `Views` FROM `RayVideoFiles` WHERE `ID` = :id LIMIT 1", array('id' => $iItemId));
        if (empty($aData))
            return false;

        $sQuery = $this->_oDb->prepare("UPDATE `bx_videos_entries` SET `views` = ? WHERE `id` = ?", $aData['Views'], $iNewID);
        return $this->_oDb->query($sQuery);
    }

    /**
     * Transfers favorites from Dolphin to UNA.
     * @param int $iItemId Dolphin video ID
     * @param int $iNewID UNA video ID
     * @return boolean|int
     */
    private function transferFavorites($iItemId, $iNewID)
    {
        $aData = $this->_mDb->getRow("SELECT * FROM `bx_videos_favorites` WHERE `ID`=:id LIMIT 1", array('id' => $iItemId));
        if (empty($aData))
            return false;

        $iProfileId = $this->getProfileId((int)$aData['Profile']);
        if (!$iProfileId)
            return false;

        $sQuery = $this->_oDb->prepare("INSERT INTO `bx_videos_favorites_track` SET `object_id` = ?, `author_id` = ?, `date` = ?", $iNewID, $iProfileId, ($aData['Date'] ? $aData['Date'] : time()));
        return $this->_oDb->query($sQuery);
    }

    /**
     * Transfers simple votes (svotes) from Dolphin to UNA.
     * @param int $iItemId Dolphin video ID
     * @param int $iNewID UNA video ID
     * @return boolean|int
     */
    private function transferSVotes($iItemId, $iNewID)
    {
        $aData = $this->_mDb->getRow("SELECT * FROM `bx_videos_rating` WHERE `gal_id`=:id LIMIT 1", array('id' => $iItemId));
        if (empty($aData))
            return false;

        $sQuery = $this->_oDb->prepare("INSERT INTO `bx_videos_svotes` SET `object_id` = ?, `count` = ?, `sum` = ?", $iNewID, $aData['gal_rating_count'], $aData['gal_rating_sum']);
        $this->_oDb->query("UPDATE `bx_videos_entries` SET `svotes` = :votes WHERE `id` = :id", array('id' => $iItemId, 'votes' => $aData['gal_rating_count']));
        return $this->_oDb->query($sQuery);
    }

    /**
     * Transfers the featured flag from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferFeaturedField($aValue, $iId)
    {
        if (isset($aValue['Featured']) && $this->_oDb->isFieldExists('bx_videos_entries', 'featured')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `featured` = :featured WHERE `id` = :id", array('featured' => (int)$aValue['Featured'], 'id' => $iId));
        }
    }

    /**
     * Transfers the duration field from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferDurationField($aValue, $iId)
    {
        if (isset($aValue['Time']) && $this->_oDb->isFieldExists('bx_videos_entries', 'duration')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `duration` = :duration WHERE `id` = :id", array('duration' => (int)$aValue['Time'], 'id' => $iId));
        }
    }

    /**
     * Transfers the extension field from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferExtField($aValue, $iId)
    {
        if (isset($aValue['Ext']) && $this->_oDb->isFieldExists('bx_videos_entries', 'ext')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `ext` = :ext WHERE `id` = :id", array('ext' => $aValue['Ext'], 'id' => $iId));
        }
    }

    /**
     * Transfers the comments count field from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferCommentsCountField($aValue, $iId)
    {
        if (isset($aValue['CommentsCount']) && $this->_oDb->isFieldExists('bx_videos_entries', 'comments')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `comments` = :comments WHERE `id` = :id", array('comments' => (int)$aValue['CommentsCount'], 'id' => $iId));
        }
    }

    /**
     * Transfers the allow_view_to field from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferAllowViewToField($aValue, $iId)
    {
        if (isset($aValue['AllowAlbumView']) && $this->_oDb->isFieldExists('bx_videos_entries', 'allow_view_to')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `allow_view_to` = :allow_view_to WHERE `id` = :id", array('allow_view_to' => (int)$aValue['AllowAlbumView'], 'id' => $iId));
        }
    }

    /**
     * Transfers the status_admin field from Dolphin to UNA.
     * @param array $aValue Dolphin video row
     * @param int $iId UNA video ID
     * @return void
     */
    private function transferStatusAdminField($aValue, $iId)
    {
        if (isset($aValue['admin_status']) && $this->_oDb->isFieldExists('bx_videos_entries', 'status_admin')) {
            $this->_oDb->query("UPDATE `bx_videos_entries` SET `status_admin` = :status_admin WHERE `id` = :id", array('status_admin' => $aValue['admin_status'], 'id' => $iId));
        }
    }

    public function removeContent()
    {
        if (!$this->_oDb->isTableExists($this->_sTableWithTransKey) || !$this->_oDb->isFieldExists($this->_sTableWithTransKey, $this->_sTransferFieldIdent))
            return false;

        $aRecords = $this->_oDb->getAll("SELECT * FROM `{$this->_sTableWithTransKey}` WHERE `{$this->_sTransferFieldIdent}` !=0");
        $iNumber = 0;
        if (!empty($aRecords)) {
            foreach ($aRecords as $iKey => $aValue) {
                BxDolService::call('bx_videos', 'delete_entity', array($aValue['id']));
                $iNumber++;
            }
        }

        parent::removeContent();
        return $iNumber;
    }
}

/** @} */
