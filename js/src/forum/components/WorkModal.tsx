import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { api, t } from '../util';

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  /** Editing: the work as GET /works/{key} returns it. */
  work?: any;
  onsave?: (work: any) => void;
  ondelete?: () => void;
}

const KINDS = ['book', 'show', 'movie', 'game', 'other'];

/**
 * Define a work and its ladder: one step per line, in order ("Chapter 1",
 * "S1E1", "Level 3"). Moderators also attach tags, so new discussions there
 * belong to the work.
 */
export default class WorkModal extends FormModal<Attrs> {
  title_ = '';
  kind = 'book';
  steps = '';
  catalog = '';
  tagIds: string[] = [];
  error = '';

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const work = this.attrs.work;
    if (work) {
      this.title_ = work.title;
      this.kind = work.kind;
      this.steps = (work.ladder.steps || []).join('\n');
      this.catalog = work.catalog || '';
      this.tagIds = (work.tagIds || []).map(String);
    }
  }

  className() {
    return 'SpoilerShield-modal Modal--medium';
  }

  title() {
    return this.attrs.work ? t('work.edit_title') : t('work.new_title');
  }

  content() {
    const tags = app.forum.attribute('spoilerShieldCanManageWorks') ? (app.store.all('tags') as any[]) : [];

    return (
      <div className="Modal-body">
        {this.error ? <p className="SpoilerShield-error">{this.error}</p> : null}
        <div className="Form-group">
          <label for="ss-work-title">{t('work.title')}</label>
          <input
            id="ss-work-title"
            className="FormControl"
            maxlength={150}
            value={this.title_}
            oninput={(e: InputEvent) => (this.title_ = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="ss-work-kind">{t('work.kind')}</label>
          <select
            id="ss-work-kind"
            className="FormControl"
            value={this.kind}
            onchange={(e: Event) => (this.kind = (e.target as HTMLSelectElement).value)}
          >
            {KINDS.map((k) => (
              <option value={k}>{t('kind.' + k)}</option>
            ))}
          </select>
        </div>
        <div className="Form-group">
          <label for="ss-work-steps">{t('work.steps')}</label>
          <p className="helpText">{t('work.steps_help')}</p>
          <textarea
            id="ss-work-steps"
            className="FormControl"
            rows={8}
            placeholder={String(t('work.steps_placeholder'))}
            value={this.steps}
            oninput={(e: InputEvent) => (this.steps = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="ss-work-catalog">{t('work.catalog')}</label>
          <p className="helpText">{t('work.catalog_help')}</p>
          <input
            id="ss-work-catalog"
            className="FormControl"
            maxlength={120}
            placeholder="openlibrary:OL45883W"
            value={this.catalog}
            oninput={(e: InputEvent) => (this.catalog = (e.target as HTMLInputElement).value)}
          />
        </div>
        {tags.length ? (
          <div className="Form-group">
            <label>{t('work.tags')}</label>
            <p className="helpText">{t('work.tags_help')}</p>
            <div className="SpoilerShield-tags">
              {tags.map((tag) => (
                <label className="checkbox">
                  <input
                    type="checkbox"
                    checked={this.tagIds.includes(tag.id())}
                    onchange={(e: Event) => {
                      const on = (e.target as HTMLInputElement).checked;
                      this.tagIds = on ? [...this.tagIds, tag.id()] : this.tagIds.filter((id) => id !== tag.id());
                    }}
                  />{' '}
                  {tag.name()}
                </label>
              ))}
            </div>
          </div>
        ) : null}
        <div className="Form-group SpoilerShield-modal-buttons">
          <Button type="submit" className="Button Button--primary" loading={this.loading}>
            {t('work.save')}
          </Button>
          {this.attrs.work?.canEdit ? (
            <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()} disabled={this.loading}>
              {t('work.delete')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  async onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    this.error = '';
    const work = this.attrs.work;
    const body: any = {
      title: this.title_,
      kind: this.kind,
      steps: this.steps
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean),
      catalog: this.catalog.trim(),
    };
    if (app.forum.attribute('spoilerShieldCanManageWorks')) body.tagIds = this.tagIds.map(Number);

    try {
      const doc = await api(work ? 'PATCH' : 'POST', work ? '/works/' + work.work.slice(2) : '/works', body);
      this.hide();
      this.attrs.onsave?.(doc.data);
    } catch (error: any) {
      const code = error?.response?.errors?.[0]?.code;
      this.error = String(t('work.error.' + (code || 'unknown')));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  async remove() {
    if (!confirm(String(t('work.delete_confirm')))) return;
    this.loading = true;
    this.error = '';
    try {
      await api('DELETE', '/works/' + this.attrs.work.work.slice(2));
      this.hide();
      this.attrs.ondelete?.();
    } catch (error: any) {
      this.error = String(t('work.error.' + (error?.response?.errors?.[0]?.code || 'unknown')));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
