import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import LadderPicker, { Ladder, labelFor } from './LadderPicker';
import WorkModal from './WorkModal';
import { api, t, Shield } from '../util';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  /** Replying: the discussion's mark; only a position on its work is chosen. */
  reply?: Shield;
  choice: any;
  onchoose: (choice: any) => void;
}

/**
 * The composer's spoiler mark. Starting a discussion: which work, and how far
 * into it the discussion goes. Replying: how far your reply goes (yours by
 * default), or nothing past the discussion's own place.
 */
export default class MarkModal extends FormModal<Attrs> {
  query = '';
  results: any[] | null = null;
  work: { work: string; title: string; ladder: Ladder } | null = null;
  position: number | null = null;
  timer: number | undefined;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const choice = this.attrs.choice;
    const key = this.attrs.reply ? this.attrs.reply.work : choice?.work;
    if (choice) this.position = choice.position;
    if (key) this.load(key);
    else this.search();
  }

  className() {
    return 'SpoilerShield-modal Modal--small';
  }

  title() {
    return this.attrs.reply ? t('mark.reply_title') : t('mark.discussion_title');
  }

  content() {
    return <div className="Modal-body">{this.attrs.reply ? this.replyBody() : this.discussionBody()}</div>;
  }

  replyBody(): Mithril.Children {
    const shield = this.attrs.reply!;
    if (!this.work) return LoadingIndicator.component();

    return [
      <p className="helpText">{t('mark.reply_help', { work: shield.title })}</p>,
      <div className="Form-group">
        <LadderPicker ladder={this.work.ladder} value={this.position} marking onchange={(v) => (this.position = v)} />
      </div>,
      <div className="Form-group SpoilerShield-modal-buttons">
        <Button type="submit" className="Button Button--primary" disabled={this.position === null}>
          {t('mark.use')}
        </Button>
        <Button className="Button" onclick={() => this.choose(null)}>
          {t('mark.nothing_past', { label: shield.label || shield.title })}
        </Button>
        <Button className="Button Button--link" onclick={() => this.choose(undefined)}>
          {t('mark.automatic')}
        </Button>
      </div>,
    ];
  }

  discussionBody(): Mithril.Children {
    if (this.work) {
      return [
        <p className="SpoilerShield-picked">
          <strong>{this.work.title}</strong>{' '}
          <Button className="Button Button--link" onclick={() => ((this.work = null), this.search())}>
            {t('mark.other_work')}
          </Button>
        </p>,
        <p className="helpText">{t('mark.discussion_help')}</p>,
        <div className="Form-group">
          <LadderPicker ladder={this.work.ladder} value={this.position} marking onchange={(v) => (this.position = v)} />
        </div>,
        <div className="Form-group SpoilerShield-modal-buttons">
          <Button type="submit" className="Button Button--primary">
            {t('mark.use')}
          </Button>
          <Button className="Button Button--link" onclick={() => this.choose(undefined)}>
            {t('mark.no_mark')}
          </Button>
        </div>,
      ];
    }

    return [
      <div className="Form-group">
        <input
          className="FormControl"
          type="search"
          placeholder={String(t('mark.search'))}
          value={this.query}
          oninput={(e: InputEvent) => {
            this.query = (e.target as HTMLInputElement).value;
            clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.search(), 250);
          }}
        />
      </div>,
      this.results === null ? (
        LoadingIndicator.component()
      ) : (
        <ul className="SpoilerShield-results">
          {this.results.length ? (
            this.results.map((r) => (
              <li>
                <button type="button" className="SpoilerShield-result" onclick={() => this.load(r.work)}>
                  <span className="SpoilerShield-result-title">{r.title}</span>
                  <span className="SpoilerShield-result-kind">{t('kind.' + r.kind)}</span>
                </button>
              </li>
            ))
          ) : (
            <li className="SpoilerShield-empty">{t('mark.none_found')}</li>
          )}
        </ul>
      ),
      <div className="Form-group SpoilerShield-modal-buttons">
        {app.forum.attribute('spoilerShieldCanCreateWorks') ? (
          <Button className="Button" icon="fas fa-plus" onclick={() => this.define()}>
            {t('mark.define')}
          </Button>
        ) : null}
        <Button className="Button Button--link" onclick={() => this.choose(undefined)}>
          {t('mark.no_mark')}
        </Button>
      </div>,
    ];
  }

  search() {
    api('GET', '/works?q=' + encodeURIComponent(this.query)).then((doc) => {
      // Anthology and Panels place their own chapters; a discussion can't be marked at one by hand.
      this.results = doc.data.filter((r: any) => !r.work.startsWith('an:') && !r.work.startsWith('pn:'));
      m.redraw();
    });
  }

  load(key: string) {
    this.work = null;
    api('GET', '/works/' + key).then((doc) => {
      this.work = doc.data;
      m.redraw();
    });
  }

  /** A new work, then straight back here with it picked. */
  define() {
    const back = { ...this.attrs };
    app.modal.show(WorkModal, {
      onsave: (work: any) => {
        app.modal.show(MarkModal, { ...back, choice: { work: work.work, position: null } });
      },
    });
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.work) return;
    this.choose({
      work: this.work.work,
      position: this.position,
      title: this.work.title,
      label: this.position === null ? undefined : labelFor(this.work.ladder, this.position),
    });
  }

  choose(choice: any) {
    this.attrs.onchoose(choice);
    this.hide();
  }
}
