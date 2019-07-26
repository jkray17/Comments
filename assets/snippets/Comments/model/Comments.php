<?php namespace Comments;

use APIhelpers;
use Exception;
use autoTable;
use Doctrine\Common\Cache\Cache;
use DocumentParser;
/**
 * Class Tree
 * @package Comments
 */
class Comments extends autoTable
{
    protected $tree_table = 'comments_tree';
    protected $table = 'comments';
    protected $guests_table = 'comments_guests';
    protected $pkName = 'id';
    protected $default_field = array(
        'createdby'  => 0,
        'updatedby'  => 0,
        'deletedby'  => 0,
        'updatedon'  => '0000-00-00 00:00:00',
        'deletedon'  => '0000-00-00 00:00:00',
        'deleted'    => 0,
        'published'  => 1,
        'rawcontent' => '',
        'content'    => '',
        'ip'         => '',
        'thread'     => 0,
        'context'    => 'site_content'
    );
    protected $tree_default_field = array(
        'idAncestor'        => 0,
        'idDescendant'      => 0,
        'idNearestAncestor' => 0,
        'level'             => 0
    );
    protected $messages = array();
    protected $stat;

    /**
     * autoTable constructor.
     * @param DocumentParser $modx
     * @param bool $debug
     */
    public function __construct (DocumentParser $modx, $debug = false)
    {
        parent::__construct($modx, $debug);
        $this->stat = Stat::getInstance($modx);
    }


    /**
     * @return bool
     */
    public function beginTransaction ()
    {
        return $this->modx->db->conn->begin_transaction();
    }

    /**
     * @return bool
     */
    public function rollbackTransaction ()
    {
        return $this->modx->db->conn->rollback();
    }

    /**
     * @return bool
     */
    public function commitTransaction ()
    {
        return $this->modx->db->conn->commit();
    }

    /**
     * @param int $id
     * @return int
     */
    public function getLevel ($id = 0)
    {
        $id = (int)$id;
        $out = 0;
        if ($id > 0) {
            $sql = "SELECT `level` FROM {$this->makeTable($this->tree_table)} WHERE `idAncestor` = `idDescendant` AND `idDescendant` = {$id}";
            $q = $this->query($sql);
            $out = (int)$this->modx->db->getValue($q);
        }

        return $out;
    }

    /**
     * @param $id
     * @return bool
     */
    public function exists ($id)
    {
        $id = (int)$id;
        $out = false;
        if ($id > 0) {
            $sql = "SELECT `idDescendant` FROM {$this->makeTable($this->tree_table)} WHERE `idDescendant` = {$id} LIMIT 1";
            $q = $this->query($sql);
            $out = !!($this->modx->db->getValue($q));
        }

        return $out;
    }

    /**
     *
     */
    public function close ()
    {
        parent::close();
        $this->resetMessages();
    }


    /**
     * @param $id
     * @return $this
     */
    public function edit ($id)
    {
        $id = (int)$id;
        if ($this->getID() != $id) {
            $this->close();
            $this->newDoc = false;
            $result = $this->query("SELECT 
                `c`.*,
                `g`.`name`,
                `g`.`email`,
                `t`.`idAncestor`,
                `t`.`idDescendant`,
                `t`.`idNearestAncestor`,
                `t`.`level`
            FROM {$this->makeTable($this->table)} `c`
            JOIN {$this->makeTable($this->tree_table)} `t` ON `c`.`id` = `t`.`idDescendant` 
            LEFT JOIN {$this->makeTable($this->guests_table)} `g` ON `c`.`id` = `g`.`id` 
            WHERE `t`.`idDescendant`=`t`.`idAncestor` AND `c`.`id`={$id}");
            $this->fromArray($this->modx->db->getRow($result));
            $this->store($this->toArray());
            $this->id = $this->eraseField($this->pkName);
            if (is_bool($this->id) && $this->id === false) {
                $this->id = null;
            }
        }

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return $this
     */
    public function set ($key, $value)
    {
        $ignored = array('thread', 'parent', 'context');
        if (!$this->newDoc && in_array($key, $ignored)) {
            return $this;
        }
        if ($key == 'comment') {
            $key = 'rawcontent';
        }

        return parent::set($key, $value);
    }

    /**
     * @param $key
     * @return mixed
     */
    public function get ($key)
    {
        if ($key == 'comment') {
            $key = 'rawcontent';
        }
        if (!$this->newDoc && $key == 'parent') {
            $key = 'idNearestAncestor';
        }

        return parent::get($key); // TODO: Change the autogenerated stub
    }

    /**
     * @param string $prefix
     * @param string $suffix
     * @param string $sep
     * @return array
     */
    public function toArray ($prefix = '', $suffix = '', $sep = '_')
    {
        $this->field['comment'] = isset($this->field['rawcontent']) ? $this->field['rawcontent'] : '';
        if (!$this->newDoc) {
            $this->field['parent'] = $this->field['idNearestAncestor'];
        }

        return parent::toArray($prefix, $suffix, $sep); // TODO: Change the autogenerated stub
    }

    /**
     * @param $data
     * @return $this
     */
    public function fromArray ($data)
    {
        if (isset($data['comment'])) {
            $data['rawcontent'] = $data['comment'];
        }

        return parent::fromArray($data); // TODO: Change the autogenerated stub
    }


    /**
     * @param bool $fire_events
     * @param bool $clearCache
     * @return bool|null
     */
    public function save ($fire_events = false, $clearCache = false)
    {
        $mode = $this->newDoc ? 'create' : 'update';
        $thread = $this->get('thread');
        $context = $this->get('context');
        if (!$this->newDoc) {
            $this->set('updatedon', date('Y-m-d H:i:s', $this->getTime(time())));
            $this->set('updatedby', $this->modx->getLoginUserID('web'));
            $result = $this->getInvokeEventResult('OnBeforeCommentSave', [
                'mode' => $mode,
                'comment' => $this
            ], $fire_events);
            if ($this->isChanged('rawcontent') && !$this->isChanged('content')) {
                $this->sanitizeContent();
            }
            if (!empty($result)) {
                $out = false;
                $this->addMessages($result);
            } else {
                $out = parent::save($fire_events, $clearCache);
                if ($out && ($this->isChanged('published') || $this->isChanged('deleted'))) {
                    $this->stat
                        ->updateLastComment($thread, $context)
                        ->updateCommentsCount($thread, $context);
                }
            }
        } else {
            if (!$thread || empty($context)) {
                return false;
            }
            $this->set('createdon', date('Y-m-d H:i:s', $this->getTime(time())));
            $this->set('ip', APIhelpers::getUserIP());
            $this->set('createdby', $this->modx->getLoginUserID('web'));
            $parent = (int)$this->get('parent');
            $result = $this->getInvokeEventResult('OnBeforeCommentSave', [
                'mode' => $mode,
                'comment' => $this
            ], $fire_events);
            if (empty($this->get('content'))) {
                $this->sanitizeContent();
            }
            if (!empty($result)) {
                $out = false;
                $this->addMessages($result);
            } else {
                $out = $this->saveNewComment($parent);
                if ($out && $this->get('published') && !$this->get('deleted')) {
                    $this->stat->setLastComment($out, $this->get('thread'), $this->get('context'));
                }
            }
        }
        if ($out) {
            $result = $this->getInvokeEventResult('OnCommentSave', [
                'mode' => $mode,
                'comment' => $this
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            }
            if ($clearCache) {
                $this->dropCache($this->get('thread'), $this->get('context'))->dropCache();
            }
        }

        return $out;
    }

    /**
     * @param bool $fire_events
     * @return $this
     */
    public function preview ($fire_events = true)
    {
        $this->invokeEvent('OnBeforeCommentSave', [
            'mode' => 'preview',
            'comment' => $this
        ], $fire_events);
        if (empty($this->get('content'))) {
            $this->sanitizeContent();
        }

        return $this;
    }

    /**
     * @param $ids
     * @param bool $fire_events
     * @return bool|int
     * @throws Exception
     */
    public function delete ($ids, $fire_events = false, $clearCache = false)
    {
        $out = false;
        $_ids = $this->cleanIDs($ids, ',');
        if (is_array($_ids) && !empty($_ids)) {
            $result = $this->getInvokeEventResult('OnBeforeCommentsDelete', [
                'ids' => $_ids
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            } else {
                $id = $this->sanitarIn($_ids);
                if (!empty($id)) {
                    $uid = (int)$this->modx->getLoginUserID('web');
                    $deletedon = date('Y-m-d H:i:s', $this->getTime(time()));
                    $q = $this->query("UPDATE {$this->makeTable($this->table)} SET `deleted`=1, `deletedby`={$uid}, `deletedon`='{$deletedon}' WHERE `id` IN ({$id})");
                    if ($out = $this->modx->db->getAffectedRows($q)) {
                        $result = $this->getInvokeEventResult('OnCommentsDelete', [
                            'ids' => $_ids
                        ], $fire_events);
                        if (!empty($result)) {
                            $this->addMessages($result);
                        }
                        $this->updateStat($_ids, $clearCache);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @param bool $fire_events
     * @return bool|int
     * @throws Exception
     */
    public function undelete($ids, $fire_events = false, $clearCache = false) {
        $out = false;
        $_ids = $this->cleanIDs($ids, ',');
        if (is_array($_ids) && !empty($_ids)) {
            $result = $this->getInvokeEventResult('OnBeforeCommentsUndelete', [
                'ids' => $_ids
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            } else {
                $id = $this->sanitarIn($_ids);
                if (!empty($id)) {
                    $deletedon = '0000-00-00 00:00:00';
                    $q = $this->query("UPDATE {$this->makeTable($this->table)} SET `deleted`=0, `deletedby`=0, `deletedon`='{$deletedon}' WHERE `id` IN ({$id})");
                    if ($out = $this->modx->db->getAffectedRows($q)) {
                        $result = $this->getInvokeEventResult('OnCommentsUndelete', [
                            'ids' => $_ids
                        ], $fire_events);
                        if (!empty($result)) {
                            $this->addMessages($result);
                        }
                        $this->updateStat($_ids, $clearCache);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @param bool $fire_events
     * @return bool|int
     * @throws Exception
     */
    public function publish($ids, $fire_events = false, $clearCache = false) {
        $out = false;
        $_ids = $this->cleanIDs($ids, ',');
        if (is_array($_ids) && !empty($_ids)) {
            $result = $this->getInvokeEventResult('OnBeforeCommentsPublish', [
                'ids' => $_ids
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            } else {
                $id = $this->sanitarIn($_ids);
                if (!empty($id)) {
                    $q = $this->query("UPDATE {$this->makeTable($this->table)} SET `published`=1 WHERE `id` IN ({$id})");
                    if ($out = $this->modx->db->getAffectedRows($q)) {
                        $result = $this->getInvokeEventResult('OnCommentsPublish', [
                            'ids' => $_ids
                        ], $fire_events);
                        if (!empty($result)) {
                            $this->addMessages($result);
                        }
                        $this->updateStat($_ids, $clearCache);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @param bool $fire_events
     * @return bool|int
     * @throws Exception
     */
    public function unpublish($ids, $fire_events = false, $clearCache = false) {
        $out = false;
        $_ids = $this->cleanIDs($ids, ',');
        if (is_array($_ids) && !empty($_ids)) {
            $result = $this->getInvokeEventResult('OnBeforeCommentsUnpublish', [
                'ids' => $_ids
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            } else {
                $id = $this->sanitarIn($_ids);
                if (!empty($id)) {
                    $q = $this->query("UPDATE {$this->makeTable($this->table)} SET `published`=0  WHERE `id` IN ({$id})");
                    if ($out = $this->modx->db->getAffectedRows($q)) {
                        $result = $this->getInvokeEventResult('OnCommentsUnpublish', [
                            'ids' => $_ids
                        ], $fire_events);
                        if (!empty($result)) {
                            $this->addMessages($result);
                        }
                        $this->updateStat($_ids, $clearCache);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @param bool $fire_events
     * @return bool|int
     * @throws Exception
     */
    public function remove($ids, $fire_events = false, $clearCache = false) {
        $out = false;
        $_ids = $this->cleanIDs($ids, ',');
        $update = array();
        if (is_array($_ids) && !empty($_ids)) {
            $_ids = $this->getChildren($_ids);
            $result = $this->getInvokeEventResult('OnBeforeCommentsRemove', [
                'ids' => $_ids
            ], $fire_events);
            if (!empty($result)) {
                $this->addMessages($result);
            } else {
                $id = $this->sanitarIn($_ids);
                if (!empty($id)) {
                    $q = $this->modx->db->query("SELECT DISTINCT `thread`, `context` FROM {$this->makeTable($this->table)} WHERE `id` IN ($id)");
                    while ($row = $this->modx->db->getRow($q)) {
                        $update[] = $row;
                    }
                    if ($this->beginTransaction()) {
                        $q = $this->query("DELETE FROM {$this->makeTable($this->tree_table)} WHERE `idDescendant` IN ({$id})");
                        $out = $this->modx->db->getAffectedRows($q);
                        if ($out) {
                            $this->query("DELETE FROM {$this->makeTable($this->table)} WHERE `id` IN ({$id})");
                        }
                        if ($this->commitTransaction()) {
                            $result = $this->getInvokeEventResult('OnCommentsRemove', [
                                'ids' => $_ids
                            ], $fire_events);
                            if (!empty($result)) {
                                $this->addMessages($result);
                            }
                            foreach ($update as $row) {
                                $this->stat->updateLastComment($row['thread'], $row['context'])->updateCommentsCount($row['thread'], $row['context']);
                                if ($clearCache) {
                                    $this->dropCache($row['thread'], $row['context']);
                                }
                            }
                            if ($clearCache) {
                                $this->dropCache();
                            }
                        } else {
                            $this->rollbackTransaction();
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @return $this
     */
    protected function updateStat($ids, $clearCache = false) {
        $id = $this->sanitarIn($ids);
        if ($id) {
            $q = $this->query("SELECT DISTINCT `thread`, `context` FROM {$this->makeTable($this->table)} WHERE `id` IN ($id)");
            while ($row = $this->modx->db->getRow($q)) {
                $this->stat->updateLastComment($row['thread'], $row['context'])->updateCommentsCount($row['thread'], $row['context']);
                if ($clearCache) {
                    $this->dropCache($row['thread'], $row['context']);
                }
            }
            if ($clearCache) {
                $this->dropCache();
            }
        }
        
        return $this;
    }

    /**
     * Удаляет комментарии с нарушенными связями
     * @throws Exception
     */
    public function removeLostComments() {
        $q = $this->query("SELECT `idAncestor` FROM `modx_comments_tree` LEFT JOIN `modx_comments` ON `idNearestAncestor` = `id` WHERE `idNearestAncestor` > 0 AND iSNULL(`id`)");
        $ids = $this->modx->db->getColumn('idAncestor', $q);
        if ($ids) {
            $this->remove($ids, true, true);
        }
    }


    /**
     * @return $this
     */
    public function sanitizeContent ()
    {
        $content = $this->get('rawcontent');
        $content = APIhelpers::sanitarTag(strip_tags($content));
        $content = preg_replace(array('/(\s?\v){3,}/u', '/\h+/u'), array('$1$1', ' '), $content);
        $this->set('content', nl2br(trim($content)));

        return $this;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function saveNewComment ($id = 0)
    {
        $out = false;
        $idNewEntry = 0;
        if ($id >= 0) {
            $id = $this->exists($id)
                ? (int)$id
                : 0;
            if ($this->beginTransaction()) {
                $level = $id > 0
                    ? $this->getLevel($id) + 1
                    : 0;
                if ($idNewEntry = parent::save()) {
                    if ($idNewEntry > 0) {
                        if (!$this->modx->getLoginUserID('web')) {
                            $name = $this->escape($this->get('name'));
                            $email = $this->escape($this->get('email'));
                            $this->query("INSERT INTO {$this->makeTable($this->guests_table)} (`id`, `name`, `email`) VALUES ({$idNewEntry}, '{$name}', '{$email}')");
                        }
                        $sql = "INSERT INTO {$this->makeTable($this->tree_table)} (`idAncestor`, `idDescendant`, `idNearestAncestor`, `level`)
                                 SELECT `idAncestor`, {$idNewEntry}, {$id}, {$level}
                                   FROM {$this->makeTable($this->tree_table)}
                                  WHERE `idDescendant` = {$id}
                              UNION ALL SELECT {$idNewEntry}, {$idNewEntry}, {$id}, {$level}";
                        if ($this->query($sql) && $this->commitTransaction()) {
                            $out = $idNewEntry;
                        }
                    }
                }
            }
            if (!$out) {
                $this->rollbackTransaction();
            }
        }
        if ($idNewEntry) {
            $out = $idNewEntry;
        }

        return $out;
    }

    /**
     * @param $id
     * @return array
     */
    public function getBranchIds ($id)
    {
        $id = (int)$id;
        $out = array();
        if ($id > 0) {
            $sql = "SELECT `idDescendant` FROM {$this->makeTable($this->tree_table)} WHERE `idAncestor` ={$id}";
            $q = $this->query($sql);
            while ($row = $this->modx->db->getRow($q)) {
                $out[] = (int)$row['idDescendant'];
            }
        }

        return $out;
    }

    /**
     * @param $ids
     * @return array
     * @throws Exception
     */
    public function getChildren($ids) {
        $out = array();
        $_ids = $this->cleanIDs($ids, ',');
        if (is_array($_ids) && !empty($_ids)) {
            $id = $this->sanitarIn($_ids);
            if (!empty($id)) {
                $q = $this->query("SELECT `idDescendant` FROM {$this->makeTable($this->tree_table)} WHERE `idAncestor` IN ({$id})");
                while ($row = $this->modx->db->getRow($q)) {
                    $out[] = $row['idDescendant'];
                }
            }
        }

        return $out;
    }

    /**
     * @param $editTime
     * @return bool
     */
    public function isEditable ($editTime = 0)
    {
        $out = $this->getID()
            && count($this->getBranchIds($this->getID())) === 1
            && $this->get('published')
            && !$this->get('deleted')
            && (!$editTime || time() + $this->modx->getConfig('server_offset_time') - $this->getTime($this->get('createdon') < $editTime));

        return $out;
    }

    /**
     * @param array $messages
     * @return $this
     */
    public function addMessages (array $messages = array())
    {
        if (!empty($messages)) {
            foreach ($messages as $message) {
                if (is_scalar($message)) {
                    $this->messages[] = $message;
                }
            }
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getMessages ()
    {
        return $this->messages;
    }

    /**
     *
     */
    public function resetMessages ()
    {
        $this->messages = [];

        return $this;
    }

    /**
     * @param int $thread
     * @param string $context
     * @return Comments
     */
    public function dropCache($thread = 0, $context = ''){
        if (isset($this->modx->cache) && ($this->modx->cache instanceof Cache)) {
            if ($thread && $context) {
                $key = $context . '_' . $thread . '_comments_data';
                $this->modx->cache->delete($key);
                $this->modx->cache->delete($key . '_moderation');
            } else {
                $this->modx->cache->delete('recent_comments_data');
            }
        }

        return $this;
    }

    /**
     *
     */
    public function createTable ()
    {
        $this->modx->db->query("
            CREATE TABLE IF NOT EXISTS {$this->makeTable($this->table)} (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `thread` int(11) NOT NULL,
            `context` varchar(255) NOT NULL DEFAULT 'site_content',
            `createdby` int(11) NOT NULL DEFAULT 0,
            `updatedby` int(11) NOT NULL DEFAULT 0,
            `deletedby` int(11) NOT NULL DEFAULT 0,
            `deleted` tinyint(1) NOT NULL DEFAULT 0,
            `published` tinyint(1) NOT NULL DEFAULT 0,
            `rawcontent` text NOT NULL,
            `content` text NOT NULL,
            `createdon` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updatedon` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `deletedon` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `ip` varchar(16) NOT NULL DEFAULT '0.0.0.0',
            PRIMARY KEY (`id`),
            KEY `id_idx` (`id`,`createdby`),
            KEY `thread` (`thread`, `context`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        $this->modx->db->query("
            CREATE TABLE IF NOT EXISTS {$this->makeTable('comments_guests')} (
            `id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `email` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            CONSTRAINT `comments_guests_ibfk_1` 
            FOREIGN KEY (`id`) 
            REFERENCES {$this->makeTable($this->table)} (`id`) 
            ON DELETE CASCADE ON UPDATE CASCADE,
            KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        $this->query("
            CREATE TABLE IF NOT EXISTS {$this->makeTable($this->tree_table)} (
            `idAncestor` int(11) NOT NULL,
            `idDescendant` int(11) NOT NULL,
            `idNearestAncestor` int(11) NOT NULL,
            `level` smallint(6) NOT NULL DEFAULT 1,
            PRIMARY KEY (`idAncestor`,`idDescendant`),
            KEY `idDescendant` (`idDescendant`),
            KEY `main` (`idAncestor`,`idDescendant`,`idNearestAncestor`,`level`),
            KEY `idNearestAncestor` (`idNearestAncestor`),
            CONSTRAINT `commentsTree_ibfk_1` 
            FOREIGN KEY (`idAncestor`) 
            REFERENCES {$this->makeTable($this->table)} (`id`) 
            ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `commentsTree_ibfk_2` 
            FOREIGN KEY (`idDescendant`) 
            REFERENCES {$this->makeTable($this->table)} (`id`) 
            ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        $this->query("
            INSERT INTO {$this->makeTable('system_eventnames')} (`name`, `groupname`) VALUES 
            ('OnBeforeCommentSave', 'Comments Events'),
            ('OnCommentSave', 'Comments Events'),
            ('OnBeforeCommentsDelete', 'Comments Events'),
            ('OnCommentsDelete', 'Comments Events'),
            ('OnBeforeCommentsPublish', 'Comments Events'),
            ('OnCommentsPublish', 'Comments Events'),
            ('OnBeforeCommentsUnpublish', 'Comments Events'),
            ('OnCommentsUnpublish', 'Comments Events'),
            ('OnBeforeCommentsRemove', 'Comments Events'),
            ('OnCommentsRemove', 'Comments Events')
        ");
        $this->stat->createTable();
    }

}
