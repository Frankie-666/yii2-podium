<table class="table table-hover">
    <?= $this->render('/elements/forum/_thread_header') ?>
    <?= $this->render('/elements/forum/_thread_list', ['forum' => $forum]) ?>
</table>