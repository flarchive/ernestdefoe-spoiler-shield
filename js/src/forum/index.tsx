import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Icon from 'flarum/common/components/Icon';
import listItems from 'flarum/common/helpers/listItems';
import type Mithril from 'mithril';
import Veil from './components/Veil';
import { Shield, shieldOf, t, loadChunk, openProgress } from './util';

declare const m: Mithril.Static;

/*
 * forum.js is on every page, so it only does what every page needs: drawing
 * a hidden discussion or post as a veil (the words never arrived, the server
 * kept them), the small marks on visible ones, the composer chips and the
 * route. The modals and the progress page are a lazy chunk.
 */

/** The composer's choice: undefined = automatic, null = no mark, or a work and position. */
type Choice = undefined | null | { work: string; position: number | null; label?: string; title?: string };

const chip = (label: Mithril.Children, onclick: () => void, active: boolean) => (
  <button type="button" className={'Button SpoilerShield-chip' + (active ? ' SpoilerShield-chip--active' : '')} onclick={onclick}>
    <Icon name="fas fa-user-secret" />
    <span className="Button-label">{label}</span>
  </button>
);

app.initializers.add('ernestdefoe/spoiler-shield', () => {
  app.routes['spoiler-shield'] = { path: '/spoilers', component: () => loadChunk().then(async (c) => (await c.loadCore(), c)) } as any;

  // ---- The discussion list --------------------------------------------------
  extend('flarum/forum/components/DiscussionListItem', 'elementAttrs', function (this: any, attrs: any) {
    if (shieldOf(this.attrs.discussion)?.hidden) attrs.className = (attrs.className || '') + ' SpoilerShield-hiddenItem';
  });

  // A hidden discussion isn't a link (there'd be nothing to read): its title is the veil.
  override('flarum/forum/components/DiscussionListItem', 'mainView', function (this: any, original: () => Mithril.Children) {
    const discussion = this.attrs.discussion;
    const shield = shieldOf(discussion);
    if (!shield?.hidden) return original();

    return (
      <div className="DiscussionListItem-main SpoilerShield-main">
        <h2 className="DiscussionListItem-title">
          <Veil shield={shield} type="discussions" id={discussion.id()} compact />
        </h2>
        <ul className="DiscussionListItem-info">{listItems(this.infoItems().toArray())}</ul>
      </div>
    );
  });

  // Visible but marked: where it sits (Marquee shows its own episode label, Panels the chapter in the title).
  extend('flarum/forum/components/DiscussionListItem', 'infoItems', function (this: any, items: ItemList<any>) {
    const shield = shieldOf(this.attrs.discussion);
    if (!shield || shield.hidden || !shield.label || shield.work.startsWith('mq:') || shield.work.startsWith('pn:')) return;
    items.add(
      'spoilerShield',
      <span className="SpoilerShield-mark" title={String(t('mark.title', { work: shield.title }))}>
        <Icon name="fas fa-user-secret" /> {shield.label}
      </span>,
      5
    );
  });

  // ---- The discussion page ----------------------------------------------------
  extend('flarum/forum/components/DiscussionHero', 'items', function (this: any, items: ItemList<any>) {
    const discussion = this.attrs.discussion;
    const shield = shieldOf(discussion);
    if (!shield?.hidden || !items.has('title')) return;
    items.setContent(
      'title',
      <h1 className="DiscussionHero-title SpoilerShield-heroTitle">
        <Veil shield={shield} type="discussions" id={discussion.id()} />
      </h1>
    );
  });

  // Where you're up to in this discussion's work, and the way to change it.
  extend('flarum/forum/components/DiscussionPage', 'sidebarItems', function (this: any, items: ItemList<any>) {
    const discussion = this.discussion;
    const shield = shieldOf(discussion);
    if (!shield || !app.session.user) return;
    items.add(
      'spoilerShield',
      <div className="SpoilerShield-progressCard">
        <div className="SpoilerShield-progressCard-work">
          <Icon name="fas fa-user-secret" /> {shield.title}
        </div>
        <div className="SpoilerShield-progressCard-at">
          {shield.viewer
            ? t('sidebar.you_are_at', { label: shield.viewer.label })
            : // Anthology and Panels series keep the place themselves: nothing read yet, rather than nothing said.
              t(/^(an|pn):/.test(shield.work) ? 'sidebar.not_started' : 'sidebar.not_set')}
        </div>
        <button type="button" className="Button Button--block" onclick={() => openProgress(shield.work)}>
          {shield.viewer ? t('sidebar.change') : t('sidebar.set')}
        </button>
      </div>,
      -20
    );
  });

  // ---- Posts --------------------------------------------------------------------
  override('flarum/forum/components/CommentPost', 'content', function (this: any, original: () => Mithril.Children[]) {
    const post = this.attrs.post;
    const shield = shieldOf(post);
    const items = original();
    if (!shield?.hidden || this.isEditing()) return items;

    // The post's header stays (who, when); its body is the veil.
    return items.flatMap((vnode: any) =>
      vnode && vnode.attrs && 'contentHtml' in vnode.attrs
        ? [
            m(vnode.tag, { ...vnode.attrs, contentHtml: '' }),
            <Veil shield={shield} type="posts" id={post.id()} compact={!!shieldOf(post.discussion())?.hidden} />,
          ]
        : [vnode]
    );
  });

  extend('flarum/forum/components/CommentPost', 'headerItems', function (this: any, items: ItemList<any>) {
    const shield = shieldOf(this.attrs.post);
    if (!shield || shield.hidden || !shield.label) return;
    items.add(
      'spoilerShield',
      <span className="SpoilerShield-mark" title={String(t('mark.title', { work: shield.title }))}>
        <Icon name="fas fa-user-secret" /> {shield.revealed ? t('mark.revealed', { label: shield.label }) : shield.label}
      </span>,
      -10
    );
  });

  // ---- The composer ---------------------------------------------------------------
  // A new discussion: none, or a work and a place in it. Below the title (phones lift the first item).
  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<any>) {
    const fields = this.composer.fields;
    const choice: Choice = fields.spoilerShield;
    const label = choice
      ? choice.label
        ? `${choice.title} · ${choice.label}`
        : choice.title
      : fields.marquee
        ? t('composer.from_marquee')
        : t('composer.mark');

    items.add(
      'spoilerShield',
      chip(
        label,
        () =>
          loadChunk().then((c) =>
            app.modal.show(c.MarkModal, {
              choice,
              onchoose: (next: Choice) => {
                fields.spoilerShield = next;
                m.redraw();
              },
            })
          ),
        !!choice
      ),
      -10
    );
  });

  extend('flarum/forum/components/DiscussionComposer', 'data', function (this: any, data: any) {
    const choice: Choice = this.composer.fields.spoilerShield;
    if (choice !== undefined) data.spoilerShield = choice && { work: choice.work, position: choice.position };
  });

  // A reply in a discussion with a place: it's stamped with yours unless you say otherwise.
  extend('flarum/forum/components/ReplyComposer', 'headerItems', function (this: any, items: ItemList<any>) {
    const shield: Shield | null = shieldOf(this.attrs.discussion);
    if (!shield || !app.session.user) return;
    const fields = this.composer.fields;
    const choice: Choice = fields.spoilerShield;
    const ahead = shield.viewer && (shield.position === null || shield.viewer.position > shield.position);
    const label =
      choice === null
        ? t('composer.reply_nothing_past', { label: shield.label || shield.title })
        : choice
          ? t('composer.reply_at', { label: choice.label })
          : shield.position !== null && ahead
            ? t('composer.reply_at', { label: shield.viewer!.label })
            : t('composer.reply_mark');

    items.add(
      'spoilerShield',
      chip(
        label,
        () =>
          loadChunk().then((c) =>
            app.modal.show(c.MarkModal, {
              reply: shield,
              choice,
              onchoose: (next: Choice) => {
                fields.spoilerShield = next;
                m.redraw();
              },
            })
          ),
        choice !== undefined || !!(shield.position !== null && ahead)
      ),
      -10
    );
  });

  extend('flarum/forum/components/ReplyComposer', 'data', function (this: any, data: any) {
    const choice: Choice = this.composer.fields.spoilerShield;
    if (choice !== undefined) data.spoilerShield = choice && { position: choice.position };
  });

  // ---- The way to the progress page ------------------------------------------------
  extend('flarum/forum/components/SessionDropdown', 'items', function (items: ItemList<any>) {
    items.add(
      'spoilerShield',
      <LinkButton icon="fas fa-user-secret" href={app.route('spoiler-shield')}>
        {t('nav.progress')}
      </LinkButton>,
      55
    );
  });
});
