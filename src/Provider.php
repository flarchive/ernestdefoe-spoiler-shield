<?php

namespace ErnestDefoe\SpoilerShield;

use ErnestDefoe\SpoilerShield\Integration\AnthologyChapters;
use ErnestDefoe\SpoilerShield\Integration\PanelsChapters;
use Flarum\Discussion\Discussion;
use ErnestDefoe\SpoilerShield\Notification\GatedEmailDriver;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Notification\NotificationSyncer;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per request (or job): it remembers which discussions were marked by hand.
        $this->container->singleton(MarkWriter::class);

        // flarum/realtime may be absent: then its class is too.
        if (class_exists(\Flarum\Realtime\Push\Payload\Generator::class)) {
            $this->container->bind(\Flarum\Realtime\Push\Payload\Generator::class, Integration\RealtimePublicPayload::class);
        }
    }

    public function boot(): void
    {
        // Wrap the email driver that's there, whoever registered it: replacing it would undo
        // another extension's (Courier's reply-by-email). Core's notification drivers are
        // set when its provider boots, before any extension's.
        $drivers = NotificationSyncer::getNotificationDrivers();
        if (isset($drivers['email']) && ! $drivers['email'] instanceof GatedEmailDriver) {
            NotificationSyncer::addNotificationDriver('email', new GatedEmailDriver(
                $drivers['email'],
                $this->container->make(Gate::class),
                $this->container->make(MarkStore::class),
            ));
        }

        Discussion::saving(fn (Discussion $discussion) => $this->container->make(MarkWriter::class)->discussionModelSaving($discussion));
        // On the subclass: Eloquent fires a model's events under its own class, and replies are CommentPosts.
        \Flarum\Post\CommentPost::saving(fn (\Flarum\Post\Post $post) => $this->container->make(MarkWriter::class)->postMoving($post));

        // Anthology may be absent: only then are its classes missing, and nothing of it ever saves.
        if (class_exists(\Ernestdefoe\Anthology\Chapter::class)) {
            \Ernestdefoe\Anthology\Chapter::saved(fn ($chapter) => AnthologyChapters::saved($chapter));
            \Ernestdefoe\Anthology\Chapter::deleted(fn ($chapter) => AnthologyChapters::deleted($chapter));
            \Flarum\Discussion\UserState::saving(fn ($state) => AnthologyChapters::reading($state));
        }
        if (class_exists(\Ernestdefoe\Anthology\Series::class)) {
            \Ernestdefoe\Anthology\Series::deleting(fn ($series) => AnthologyChapters::seriesDeleting($series));
        }
        // Panels too (its hooks check it's switched on): a chapter's discussion follows the chapter.
        if (class_exists(\ErnestDefoe\Panels\Model\Chapter::class)) {
            \ErnestDefoe\Panels\Model\Chapter::saved(fn ($chapter) => PanelsChapters::saved($chapter));
            \ErnestDefoe\Panels\Model\Chapter::deleted(fn ($chapter) => PanelsChapters::deleted($chapter));
        }
    }
}
