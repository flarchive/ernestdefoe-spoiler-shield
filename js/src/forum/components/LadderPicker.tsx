import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { t } from '../util';

export const FINISHED = 2147483647;
const SEASON = 100000;
const SEASON_END = 99999;

export interface Ladder {
  type: 'steps' | 'episodes' | 'finished' | 'chapters';
  steps?: string[];
  seasons?: { number: number; episodes: number }[];
  chapters?: number[];
}

/** A position's words, made the same way the server makes them. */
export function labelFor(ladder: Ladder, position: number): string {
  if (position === FINISHED) return String(t('ladder.finished'));
  if (ladder.type === 'steps') return ladder.steps?.[position - 1] ?? '#' + position;
  if (ladder.type === 'episodes') {
    const season = Math.floor(position / SEASON);
    const episode = position % SEASON;
    return episode === SEASON_END ? String(t('ladder.season', { number: season })) : `S${season}E${episode}`;
  }
  return String(t('ladder.chapter', { number: position }));
}

interface Attrs {
  ladder: Ladder;
  value: number | null;
  onchange: (position: number | null) => void;
  /** The marks a discussion can take include "the whole season" and "the whole thing". */
  marking?: boolean;
}

/**
 * Pick a rung: a step from a list, or a season and an episode. Text only:
 * labels are what the work's owner typed, rendered as text.
 */
export default class LadderPicker extends Component<Attrs> {
  view(): Mithril.Children {
    const { ladder, value, onchange, marking } = this.attrs;

    if (ladder.type === 'steps') {
      return (
        <select className="FormControl" value={value ?? ''} onchange={(e: Event) => onchange(this.number(e))}>
          <option value="">{t('ladder.choose')}</option>
          {(ladder.steps || []).map((label, i) => (
            <option value={i + 1}>{label}</option>
          ))}
          {marking ? <option value={FINISHED}>{t('ladder.whole_thing')}</option> : null}
        </select>
      );
    }

    if (ladder.type === 'episodes') {
      const seasons = ladder.seasons || [];
      const season = value && value !== FINISHED ? Math.floor(value / SEASON) : null;
      const current = seasons.find((s) => s.number === season);
      const episode = value && value !== FINISHED ? value % SEASON : null;
      // Progress at a season's end is stored as SEASON_END: shown as its last episode.
      const shownEpisode = episode === SEASON_END && current && !marking ? current.episodes : episode;

      return (
        <div className="SpoilerShield-episodes">
          <select
            className="FormControl"
            value={value === FINISHED ? 'finished' : (season ?? '')}
            onchange={(e: Event) => {
              const v = (e.target as HTMLSelectElement).value;
              onchange(v === 'finished' ? FINISHED : v ? Number(v) * SEASON + (marking ? SEASON_END : 1) : null);
            }}
          >
            <option value="">{t('ladder.season_choose')}</option>
            {seasons.map((s) => (
              <option value={s.number}>{t('ladder.season', { number: s.number })}</option>
            ))}
            {marking ? <option value="finished">{t('ladder.whole_thing')}</option> : null}
          </select>
          {current ? (
            <select
              className="FormControl"
              value={shownEpisode ?? ''}
              onchange={(e: Event) => onchange(season! * SEASON + Number((e.target as HTMLSelectElement).value))}
            >
              {marking ? <option value={SEASON_END}>{t('ladder.whole_season')}</option> : null}
              {Array.from({ length: current.episodes }, (_, i) => (
                <option value={i + 1}>{t('ladder.episode', { number: i + 1 })}</option>
              ))}
            </select>
          ) : null}
        </div>
      );
    }

    if (ladder.type === 'chapters') {
      return <p className="helpText">{t('ladder.chapters_auto')}</p>;
    }

    if (marking) {
      return (
        <select className="FormControl" value={value ?? ''} onchange={(e: Event) => onchange(this.number(e))}>
          <option value="">{t('ladder.no_point')}</option>
          <option value={FINISHED}>{t('ladder.whole_thing')}</option>
        </select>
      );
    }

    return <p className="helpText">{t('ladder.finished_only')}</p>;
  }

  number(e: Event): number | null {
    const v = (e.target as HTMLSelectElement).value;
    return v ? Number(v) : null;
  }
}
