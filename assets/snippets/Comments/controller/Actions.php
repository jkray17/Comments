<?php namespace Comments;

use DocumentParser;
use RuntimeSharedSettings;
use Helpers\Config;
use Helpers\Lexicon;

/**
 * Class Actions
 * @package Comments
 */
class Actions
{
    protected $modx = null;
    protected $moderation = null;
    protected $commentsConfig = null;
    protected $formConfig = null;
    protected $lexicon = null;
    protected $ahCommentsElement = 'TreeViewComments';
    protected $ahFormElement = 'CommentsForm';
    protected $thread = 0;
    protected $context = 'site_content';
    protected $parent = 0;
    protected $id = 0;
    protected $lastComment = 0;
    protected $result = array();
    public $formConfigOverride = array(
        'disableSubmit' => 0,
        'api' => 2,
        'apiFormat' => 'array',
        'rtssElement' => ''
    );

    /**
     * Actions constructor.
     * @param DocumentParser $modx
     */
    public function __construct (DocumentParser $modx)
    {
        $this->modx = $modx;
        $this->thread = !empty($_POST['thread']) && $_POST['thread'] > 0 ? (int)$_POST['thread'] : 0;
        $this->parent = !empty($_POST['parent']) && $_POST['thread'] > 0 ? (int)$_POST['parent'] : 0;
        $this->id = !empty($_POST['id']) && $_POST['id'] > 0 ? (int)$_POST['id'] : 0;
        $this->lastComment = !empty($_POST['lastComment']) ? (int)$_POST['lastComment'] : 0;
        $this->loadConfig();
        $this->lexicon = new Lexicon($modx, array(
            'langDir' => 'assets/snippets/Comments/lang/',
            'lang'    => $this->getCFGDef('form', 'lang', $this->modx->getConfig('manager_language')),
            'handler' => $this->getCFGDef('form', 'lexiconHandler')
        ));
        $this->lexicon->fromFile('actions');
    }

    /**
     * @return bool
     */
    protected function loadConfig ()
    {
        $ah = RuntimeSharedSettings::getInstance($this->modx);
        $out = true;
        $config = $ah->load($this->ahFormElement . $this->thread, $this->context);
        $this->formConfig = new Config($config);
        $out = $out && !empty($config);
        $config = $ah->load($this->ahCommentsElement . $this->thread, $this->context);
        $this->commentsConfig = new Config($config);
        $out = $out && !empty($config);
        $this->moderation = new Moderation($this->modx, array(
            'moderatedByThreadCreator' => $this->getCFGDef('comments', 'moderatedByThreadCreator', 0),
            'threadCreator'            => $this->getCFGDef('comments', 'threadCreator', 0)
        ));

        return $out;
    }

    /*
     * Добавление нового комментария
     */
    public function create ()
    {
        if ($this->thread) {
            $cfg = $this->formConfig->getConfig();
            $cfg['thread'] = $this->thread;
            $cfg['mode'] = 'create';
            $this->setResult($this->modx->runSnippet(
                'FormLister',
                array_merge(
                    $cfg,
                    $this->formConfigOverride
                )
            ));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_create'));
        }
    }

    /*
     * Добавление ответа на существующий комментарий
     */
    public function reply ()
    {
        if ($this->thread && $this->parent) {
            $cfg = $this->formConfig->getConfig();
            $this->setResult($this->modx->runSnippet(
                'FormLister',
                array_merge(
                    $cfg,
                    $this->formConfigOverride
                )
            ));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_create'));
        }
    }

    /*
     * Редактирование комментария пользователем
     */
    public function update ()
    {
        if ($this->thread && $this->id) {
            $cfg = $this->formConfig->getConfig();
            $cfg['id'] = $this->id;
            $this->setResult($this->modx->runSnippet(
                'FormLister',
                array_merge(
                    $cfg,
                    $this->formConfigOverride
                )
            ));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    /*
     * Редактирование комментария модератором
     */
    public function edit ()
    {
        if ($this->thread && $this->id) {
            $cfg = $this->formConfig->loadArray($this->getCFGDef('form', 'moderation'));
            $cfg['dir'] = 'assets/snippets/Comments/FormLister/';
            $cfg['templatePath'] = 'assets/snippets/Comments/tpl/';
            $cfg['templateExtension'] = 'tpl';
            $cfg['controller'] = 'Moderation';
            $cfg['id'] = $this->id;
            $cfg['moderatedByThreadCreator'] = $this->getCFGDef('comments', 'moderatedByThreadCreator', 0);
            $cfg['threadCreator'] = $this->getCFGDef('comments', 'threadCreator', 0);
            $this->setResult($this->modx->runSnippet(
                'FormLister',
                array_merge(
                    $cfg,
                    $this->formConfigOverride
                )
            ));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    public function load ()
    {
        if ($this->thread) {
            $cfg = $this->commentsConfig->getConfig();
            $cfg['thread'] = $this->thread;
            $cfg['addWhereList'] = 'c.id > ' . $this->lastComment;
            $cfg['mode'] = 'recent';
            $this->setResult($this->modx->runSnippet('DocLister', $cfg));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_load'));
        }
    }

    public function loadComment ()
    {
        if ($this->thread && $this->id) {
            $cfg = $this->commentsConfig->getConfig();
            $cfg['thread'] = $this->thread;
            $cfg['addWhereList'] = 'c.id = ' . $this->id;
            $cfg['mode'] = 'recent';
            $this->setResult($this->modx->runSnippet('DocLister', $cfg));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error'));
        }
    }

    public function preview ()
    {
        if ($this->thread) {
            $cfg = array(
                'controller' => 'CommentPreview',
                'dir'        => 'assets/snippets/Comments/FormLister/',
                'formid'     => $this->getCFGDef('form', 'formid'),
                'api'        => 1,
                'filters'    => $this->getCFGDef('form', 'filters')
            );
            $this->setResult($this->modx->runSnippet('FormLister', $cfg));
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error'));
        }
    }

    /**
     * @throws \Exception
     */
    public function publish ()
    {
        if ($this->thread && $this->id) {
            if ($this->moderation->hasPermission('comments_publish')) {
                $data = new Comments($this->modx);
                $status = $data->publish($this->id, true, true) !== false;
                $messages = $data->getMessages();
                if (!$status && empty($messages)) {
                    $messages = $this->lexicon->get('actions.error_update');
                }
                $stat = Stat::getInstance($this->modx)->getStat($this->thread, $this->context);
                $this->setResult(array(
                    'status'   => $status,
                    'messages' => $messages,
                    'count'    => $stat['comments_count']
                ));
            } else {
                $this->setResult(false, $this->lexicon->get('actions.access_denied'));
            }
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    /**
     * @throws \Exception
     */
    public function unpublish ()
    {
        if ($this->thread && $this->id) {
            if ($this->moderation->hasPermission('comments_unpublish')) {
                $data = new Comments($this->modx);
                $status = $data->unpublish($this->id, true, true) !== false;
                $messages = $data->getMessages();
                if (!$status && empty($messages)) {
                    $messages = $this->lexicon->get('actions.error_update');
                }
                $stat = Stat::getInstance($this->modx)->getStat($this->thread, $this->context);
                $this->setResult(array(
                    'status'   => $status,
                    'messages' => $messages,
                    'count'    => $stat['comments_count']
                ));
            }
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    /**
     * @throws \Exception
     */
    public function delete ()
    {
        if ($this->thread && $this->id) {
            if ($this->moderation->hasPermission('comments_delete')) {
                $data = new Comments($this->modx);
                $status = $data->delete($this->id, true, true) !== false;
                $messages = $data->getMessages();
                if (!$status && empty($messages)) {
                    $messages = $this->lexicon->get('actions.error_update');
                }
                $stat = Stat::getInstance($this->modx)->getStat($this->thread, $this->context);
                $this->setResult(array(
                    'status'   => $status,
                    'messages' => $messages,
                    'count'    => $stat['comments_count']
                ));
            }
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    /**
     * @throws \Exception
     */
    public function undelete ()
    {
        if ($this->thread && $this->id) {
            if ($this->moderation->hasPermission('comments_undelete')) {
                $data = new Comments($this->modx);
                $status = $data->undelete($this->id, true, true) !== false;
                $messages = $data->getMessages();
                if (!$status && empty($messages)) {
                    $messages = $this->lexicon->get('actions.error_update');
                }
                $stat = Stat::getInstance($this->modx)->getStat($this->thread, $this->context);
                $this->setResult(array(
                    'status'   => $status,
                    'messages' => $messages,
                    'count'    => $stat['comments_count']
                ));
            }
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_update'));
        }
    }

    /**
     * @throws \Exception
     */
    public function remove ()
    {
        if ($this->thread && $this->id) {
            if ($this->moderation->hasPermission('comments_remove')) {
                $data = new Comments($this->modx);
                $status = $data->remove($this->id, true, true) !== false;
                $messages = $data->getMessages();
                if (!$status && empty($messages)) {
                    $messages = $this->lexicon->get('actions.error_remove');
                }
                $stat = Stat::getInstance($this->modx)->getStat($this->thread, $this->context);
                $this->setResult(array(
                    'status'   => $status,
                    'messages' => $messages,
                    'count'    => $stat['comments_count']
                ));
            }
        } else {
            $this->setResult(false, $this->lexicon->get('actions.error_remove'));
        }
    }

    /**
     * @param $config
     * @param $key
     * @param string $default
     * @return mixed
     */
    protected function getCFGDef ($config, $key, $default = '')
    {
        $config = $config . 'Config';
        if (isset($this->$config)) {
            $out = $this->$config->getCFGDef($key, $default);
        }

        return $out;
    }

    /**
     * @param $out
     * @param $message
     */
    protected function setResult ($out, $message = '')
    {
        if (empty($message)) {
            $this->result = $out;
        } else {
            $this->result = array('status' => false, 'messages' => $message);
        }
    }

    /**
     * @return array
     */
    public function getResult ()
    {
        return $this->result;
    }
}