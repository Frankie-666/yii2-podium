<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\models;

use bizley\podium\components\Cache;
use bizley\podium\components\Helper;
use bizley\podium\components\PodiumUser;
use bizley\podium\log\Log;
use Exception;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\HtmlPurifier;

/**
 * Post model
 *
 * @property integer $id
 * @property string $content
 * @property integer $thread_id
 * @property integer $forum_id
 * @property integer $author_id
 * @property integer $likes
 * @property integer $dislikes
 * @property integer $updated_at
 * @property integer $created_at
 */
class Post extends ActiveRecord
{

    public $subscribe;
    public $topic;
    
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%podium_post}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['topic', 'required', 'message' => Yii::t('podium/view', 'Topic can not be blank.'), 'on' => ['firstPost']],
            ['topic', 'validateTopic', 'on' => ['firstPost']],
            ['subscribe', 'boolean'],
            ['content', 'required'],
            ['content', 'string', 'min' => 10],
            ['content', 'filter', 'filter' => function($value) { return HtmlPurifier::process($value, Helper::podiumPurifierConfig('full')); }],
        ];
    }

    /**
     * Validates topic
     * Custom method is required because JS ES5 (and so do Yii 2) doesn't support regex unicode features.
     * @param string $attribute
     */
    public function validateTopic($attribute)
    {
        if (!$this->hasErrors()) {
            if (!preg_match('/^[\w\s\p{L}]{1,255}$/u', $this->topic)) {
                $this->addError($attribute, Yii::t('podium/view', 'Name must contain only letters, digits, underscores and spaces (255 characters max).'));
            }
        }
    }
    
    public function getPodiumUser()
    {
        return (new PodiumUser)->findOne($this->author_id);
    }
    
    public function getThread()
    {
        return $this->hasOne(Thread::className(), ['id' => 'thread_id']);
    }
    
    public function getForum()
    {
        return $this->hasOne(Forum::className(), ['id' => 'forum_id']);
    }
    
    public function getThumb()
    {
        return $this->hasOne(PostThumb::className(), ['post_id' => 'id'])->where(['user_id' => Yii::$app->user->id]);
    }
    
    public function getLatestPostsForMembers($limit = 5)
    {
        return self::find()->orderBy(['created_at' => SORT_DESC])->limit($limit)->all();
    }
    
    public function getLatestPostsForGuests($limit = 5)
    {
        return self::find()->joinWith(['forum' => function ($query) {
            $query->andWhere([Forum::tableName() . '.visible' => 1])->joinWith(['category' => function ($query) {
                $query->andWhere([Category::tableName() . '.visible' => 1]);
            }]);
        }])->orderBy(['created_at' => SORT_DESC])->limit($limit)->all();
    }
    
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        try {
            if ($insert) {
                $this->_insertWords();
            }
            else {
                $this->_updateWords();
            }
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    protected function _prepareWords()
    {
        $wordsRaw = array_unique(explode(' ', preg_replace('/\s/', ' ', strip_tags(preg_replace(['/\n/', '/\<br ?\/?\>/i'], ' ', $this->content)))));
        $allWords = [];
        foreach ($wordsRaw as $word) {
            if (mb_strlen($word, 'UTF-8') > 2 && mb_strlen($word, 'UTF-8') <= 255) {
                $allWords[] = $word;
            }
        }
        
        return $allWords;
    }
    
    protected function _addNewWords($allWords)
    {
        try {
            $newWords = $allWords;

            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyFound) {
                if (($key = array_search($vocabularyFound['word'], $allWords)) !== false) {
                    unset($newWords[$key]);
                }
            }
            $formatWords = [];
            foreach ($newWords as $word) {
                $formatWords[] = [$word];
            }
            if (!empty($formatWords)) {
                Yii::$app->db->createCommand()->batchInsert(Vocabulary::tableName(), ['word'], $formatWords)->execute();
            }
        }
        catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }
    
    protected function _insertWords()
    {
        try {
            $vocabulary = [];
            $allWords   = $this->_prepareWords();
            
            $this->_addNewWords($allWords);

            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyNew) {
                $vocabulary[] = [$vocabularyNew['id'], $this->id];
            }
            if (!empty($vocabulary)) {
                Yii::$app->db->createCommand()->batchInsert('{{%podium_vocabulary_junction}}', ['word_id', 'post_id'], $vocabulary)->execute();
            }
        }
        catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }

    protected function _updateWords()
    {
        try {
            $vocabulary = [];
            $allWords   = $this->_prepareWords();
            
            $this->_addNewWords($allWords);

            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyNew) {
                $vocabulary[$vocabularyNew['id']] = [$vocabularyNew['id'], $this->id];
            }
            if (!empty($vocabulary)) {
                Yii::$app->db->createCommand()->batchInsert('{{%podium_vocabulary_junction}}', ['word_id', 'post_id'], array_values($vocabulary))->execute();
            }
            
            $query = (new Query)->from('{{%podium_vocabulary_junction}}')->where(['post_id' => $this->id]);
            foreach ($query->each() as $junk) {
                if (!array_key_exists($junk['word_id'], $vocabulary)) {
                    Yii::$app->db->createCommand()->delete('{{%podium_vocabulary_junction}}', ['id' => $junk['id']])->execute();
                }
            }
        }
        catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }
    
    public function isMod($user_id = null)
    {
        return $this->forum->isMod($user_id);
    }
    
    public function search($forum_id, $thread_id)
    {
        $query = self::find()->where(['forum_id' => $forum_id, 'thread_id' => $thread_id]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'defaultPageSize' => 10,
                'pageSizeLimit' => false,
                'forcePageParam' => false
            ],
        ]);

        $dataProvider->sort->defaultOrder = ['id' => SORT_ASC];

        return $dataProvider;
    }
    
    public function searchByUser($user_id)
    {
        $query = self::find();
        $query->where(['author_id' => $user_id]);
        if (Yii::$app->user->isGuest) {
            $query->joinWith(['forum' => function($q) {
                $q->where([Forum::tableName() . '.visible' => 1]);
            }]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'defaultPageSize' => 10,
                'pageSizeLimit' => false,
                'forcePageParam' => false
            ],
        ]);

        $dataProvider->sort->defaultOrder = ['id' => SORT_ASC];

        return $dataProvider;
    }
    
    public function markSeen()
    {
        if (!Yii::$app->user->isGuest) {
            $threadView = ThreadView::findOne(['user_id' => Yii::$app->user->id, 'thread_id' => $this->thread_id]);
            
            if (!$threadView) {
                $threadView                   = new ThreadView;
                $threadView->user_id          = Yii::$app->user->id;
                $threadView->thread_id        = $this->thread_id;
                $threadView->new_last_seen    = $this->created_at;
                $threadView->edited_last_seen = !empty($this->edited_at) ? $this->edited_at : $this->created_at;
                $threadView->save();
                $this->thread->updateCounters(['views' => 1]);
            }
            else {
                if ($this->edited) {
                    if ($threadView->edited_last_seen < $this->edited_at) {
                        $threadView->edited_last_seen = $this->edited_at;
                        $threadView->save();
                        $this->thread->updateCounters(['views' => 1]);
                    }
                }
                else {
                    $save = false;
                    if ($threadView->new_last_seen < $this->created_at) {
                        $threadView->new_last_seen = $this->created_at;
                        $save = true;
                    }
                    if ($threadView->edited_last_seen < max($this->created_at, $this->edited_at)) {
                        $threadView->edited_last_seen = max($this->created_at, $this->edited_at);
                        $save = true;
                    }
                    if ($save) {
                        $threadView->save();
                        $this->thread->updateCounters(['views' => 1]);
                    }
                }
            }
            
            if ($this->thread->subscription) {
                if ($this->thread->subscription->post_seen == Subscription::POST_NEW) {
                    $this->thread->subscription->post_seen = Subscription::POST_SEEN;
                    $this->thread->subscription->save();
                }
            }
        }
    }
    
    public function getLatest()
    {
        $latest = [];
        
        if (Yii::$app->user->isGuest) {
            $latest = Cache::getInstance()->getElement('forum.latestposts', 'guest');
            if ($latest === false) {
                $posts = $this->getLatestPostsForGuests(5);
                foreach ($posts as $post) {
                    $latest[] = [
                        'id'      => $post->id,
                        'title'   => $post->thread->name,
                        'created' => $post->created_at,
                        'author'  => $post->podiumUser->user->getPodiumTag()
                    ];
                }
                Cache::getInstance()->setElement('forum.latestposts', 'guest', $latest);
            }
        }
        else {
            $latest = Cache::getInstance()->getElement('forum.latestposts', 'member');
            if ($latest === false) {
                $posts = $this->getLatestPostsForMembers(5);
                foreach ($posts as $post) {
                    $latest[] = [
                        'id'      => $post->id,
                        'title'   => $post->thread->name,
                        'created' => $post->created_at,
                        'author'  => $post->podiumUser->user->getPodiumTag()
                    ];
                }
                Cache::getInstance()->setElement('forum.latestposts', 'member', $latest);
            }
        }
        
        return $latest;
    }
}