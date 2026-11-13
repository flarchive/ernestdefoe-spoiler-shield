import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Shield, t, reveal, openProgress, finish } from '../util';

declare const m: Mithril.Static;

interface VeilAttrs {
  shield: Shield;
  type: 'discussions' | 'posts';
  id: string;
  compact?: boolean;
}

/**
 * Where hidden words would be: blurred bars (the words themselves never came
 * from the server), what it spoils, and the ways through: reveal this one,
 * say where you're up to, or unlock the work you finished in your collection.
 */
export default class Veil extends Component<VeilAttrs> {
  loading = false;

  view() {
    const { shield, compact } = this.attrs;
    const label = shield.label ? t('veil.spoiler_for', { label: shield.label }) : t('veil.spoiler');

    return (
      <div className={classList('SpoilerShield-veil', { 'SpoilerShield-veil--compact': compact })}>
        <div className="SpoilerShield-bars" aria-hidden="true">
          <span />
          <span />
          {compact ? null : <span />}
        </div>
        <div className="SpoilerShield-veil-body">
          <button
            type="button"
            className="SpoilerShield-reveal"
            disabled={this.loading}
            onclick={(e: MouseEvent) => this.reveal(e)}
            aria-label={String(t('veil.reveal_label', { label: shield.label || '', work: shield.title }))}
          >
            <Icon name="fas fa-user-secret" />
            <span className="SpoilerShield-reveal-what">{label}</span>
            {shield.title ? <span className="SpoilerShield-reveal-work">{shield.title}</span> : null}
            <span className="SpoilerShield-reveal-tap">{this.loading ? t('veil.revealing') : t('veil.tap')}</span>
          </button>
          {compact ? null : this.actions()}
        </div>
      </div>
    );
  }

  actions(): Mithril.Children {
    const { shield } = this.attrs;
    if (!app.session.user) return <p className="SpoilerShield-veil-note">{t('veil.guest')}</p>;

    return (
      <div className="SpoilerShield-veil-actions">
        {shield.offer ? (
          <button type="button" className="Button Button--primary SpoilerShield-offer" onclick={() => finish(shield.work)}>
            <Icon name="fas fa-check-double" /> {t('veil.offer')}
          </button>
        ) : null}
        <button type="button" className="Button Button--link SpoilerShield-progressLink" onclick={() => openProgress(shield.work)}>
          {shield.viewer ? t('veil.you_are_at', { label: shield.viewer.label }) : t('veil.set_progress')}
        </button>
      </div>
    );
  }

  async reveal(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    this.loading = true;
    try {
      await reveal(this.attrs.type, this.attrs.id);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
