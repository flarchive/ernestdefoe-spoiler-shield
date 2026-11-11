import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import LadderPicker, { FINISHED, Ladder } from './LadderPicker';
import { api, t, refreshAfterProgress } from '../util';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  work: string;
}

/** "Where are you in Dragon Throne?" One work, its ladder, and finished / forget. */
export default class ProgressModal extends FormModal<Attrs> {
  work: any = null;
  value: number | null = null;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    api('GET', '/works/' + this.attrs.work).then((doc) => {
      this.work = doc.data;
      this.value = doc.data.viewer ? doc.data.viewer.position : null;
      m.redraw();
    });
  }

  className() {
    return 'SpoilerShield-modal Modal--small';
  }

  title() {
    return this.work ? t('progress.title', { work: this.work.title }) : t('progress.loading');
  }

  content() {
    if (!this.work) return <div className="Modal-body">{LoadingIndicator.component()}</div>;
    const ladder: Ladder = this.work.ladder;

    return (
      <div className="Modal-body">
        <p className="helpText">{t('progress.help')}</p>
        {this.work.offer ? <p className="SpoilerShield-offerNote">{t('progress.offer')}</p> : null}
        <div className="Form-group">
          {this.value === FINISHED ? (
            <p className="SpoilerShield-finished">{t('progress.finished_now')}</p>
          ) : (
            <LadderPicker ladder={ladder} value={this.value} onchange={(v) => (this.value = v)} />
          )}
        </div>
        <div className="Form-group SpoilerShield-modal-buttons">
          {ladder.type !== 'finished' && ladder.type !== 'chapters' ? (
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={this.value === null || this.value === FINISHED}>
              {t('progress.save')}
            </Button>
          ) : null}
          <Button className="Button" icon="fas fa-check-double" onclick={() => this.save(FINISHED)} disabled={this.loading}>
            {t('progress.finished')}
          </Button>
          {this.work.viewer ? (
            <Button className="Button Button--link" onclick={() => this.save(null)} disabled={this.loading}>
              {t('progress.forget')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.save(this.value);
  }

  async save(position: number | null) {
    this.loading = true;
    try {
      await api('POST', '/progress', { work: this.attrs.work, position: position === FINISHED ? 'finished' : position });
      this.hide();
      refreshAfterProgress();
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
