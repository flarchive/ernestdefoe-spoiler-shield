import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import ProgressModal from './ProgressModal';
import WorkModal from './WorkModal';
import { api, t } from '../util';

declare const m: Mithril.Static;

declare const flarum: any;

let PageStructure: any;
let IndexSidebar: any;

/** Core page parts, loaded through the registry before the page draws. */
export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

/**
 * /spoilers: where I'm up to in each work, and every work to pick from. On
 * core's page structure with the index sidebar, like any forum page.
 */
export default class SpoilersPage extends Page {
  mine: any[] | null = null;
  works: any[] | null = null;
  query = '';
  timer: number | undefined;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.bodyClass = 'App--spoilerShield';
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    this.load();
  }

  load() {
    if (app.session.user) {
      api('GET', '/progress').then((doc) => {
        this.mine = doc.data;
        m.redraw();
      });
    }
    this.search();
  }

  search() {
    api('GET', '/works?q=' + encodeURIComponent(this.query)).then((doc) => {
      this.works = doc.data;
      m.redraw();
    });
  }

  view() {
    return (
      <PageStructure className="SpoilerShieldPage" sidebar={() => <IndexSidebar />}>
        <div className="SpoilerShieldPage-intro">
          <h2>
            <Icon name="fas fa-user-secret" /> {t('page.title')}
          </h2>
          <p>{t('page.intro')}</p>
        </div>
        {app.session.user ? this.mineView() : <p className="SpoilerShieldPage-guest">{t('page.guest')}</p>}
        {this.worksView()}
      </PageStructure>
    );
  }

  mineView(): Mithril.Children {
    return (
      <section className="SpoilerShieldPage-section">
        <h3>{t('page.mine')}</h3>
        {this.mine === null ? (
          LoadingIndicator.component()
        ) : this.mine.length ? (
          <ul className="SpoilerShieldPage-list">
            {this.mine.map((row) => (
              <li className="SpoilerShieldPage-row">
                <span className="SpoilerShieldPage-row-title">{row.title}</span>
                <span className="SpoilerShieldPage-row-at">{t('page.at', { label: row.label })}</span>
                <Button className="Button Button--link" onclick={() => this.progress(row.work)}>
                  {t('page.change')}
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="SpoilerShieldPage-empty">{t('page.mine_empty')}</p>
        )}
      </section>
    );
  }

  worksView(): Mithril.Children {
    return (
      <section className="SpoilerShieldPage-section">
        <div className="SpoilerShieldPage-worksHead">
          <h3>{t('page.works')}</h3>
          {app.forum.attribute('spoilerShieldCanCreateWorks') ? (
            <Button className="Button" icon="fas fa-plus" onclick={() => app.modal.show(WorkModal, { onsave: () => this.load() })}>
              {t('page.define')}
            </Button>
          ) : null}
        </div>
        <input
          className="FormControl SpoilerShieldPage-search"
          type="search"
          placeholder={String(t('page.search'))}
          value={this.query}
          oninput={(e: InputEvent) => {
            this.query = (e.target as HTMLInputElement).value;
            clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.search(), 250);
          }}
        />
        {this.works === null ? (
          LoadingIndicator.component()
        ) : this.works.length ? (
          <ul className="SpoilerShieldPage-list">
            {this.works.map((row) => (
              <li className="SpoilerShieldPage-row">
                <span className="SpoilerShieldPage-row-title">{row.title}</span>
                <span className="SpoilerShieldPage-row-kind">{t('kind.' + row.kind)}</span>
                {app.session.user ? (
                  <Button className="Button Button--link" onclick={() => this.progress(row.work)}>
                    {t('page.where')}
                  </Button>
                ) : null}
                {row.canEdit ? (
                  <Button
                    className="Button Button--icon Button--link"
                    icon="fas fa-pen"
                    aria-label={String(t('page.edit'))}
                    onclick={() => this.edit(row.work)}
                  />
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <p className="SpoilerShieldPage-empty">{t('page.works_empty')}</p>
        )}
      </section>
    );
  }

  progress(work: string) {
    app.modal.show(ProgressModal, { work });
  }

  edit(key: string) {
    api('GET', '/works/' + key).then((doc) => {
      if (!doc.data.canEdit) {
        app.alerts.show({ type: 'error' }, t('page.cannot_edit'));
        return;
      }
      app.modal.show(WorkModal, { work: doc.data, onsave: () => this.load(), ondelete: () => this.load() });
    });
  }
}
