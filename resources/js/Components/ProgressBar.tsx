// resources/js/Components/ProgressBar.tsx — barre de progression d'un morceau, cliquable si `onSeek` est fourni.
import { clamp, formatDuration } from '@/lib/time';
import type { KeyboardEvent, MouseEvent } from 'react';

interface ProgressBarProps {
    positionMs: number;
    durationMs: number;
    /** Si fourni, la barre devient un curseur : clic ou flèches pour se déplacer (écran hôte). */
    onSeek?: (positionMs: number) => void;
    /** Affiche "1:23 / 4:13" sous la barre. */
    showTimes?: boolean;
    className?: string;
}

const KEYBOARD_STEP_MS = 5000;

export default function ProgressBar({ positionMs, durationMs, onSeek, showTimes = true, className = '' }: ProgressBarProps) {
    const safeDuration = Math.max(0, durationMs);
    const position = clamp(positionMs, 0, safeDuration);
    const ratio = safeDuration > 0 ? position / safeDuration : 0;
    const interactive = onSeek !== undefined && safeDuration > 0;

    const handleClick = (event: MouseEvent<HTMLDivElement>): void => {
        if (!interactive) {
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const clickRatio = rect.width > 0 ? clamp((event.clientX - rect.left) / rect.width, 0, 1) : 0;

        onSeek(Math.round(clickRatio * safeDuration));
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (!interactive) {
            return;
        }

        if (event.key === 'ArrowRight') {
            onSeek(clamp(position + KEYBOARD_STEP_MS, 0, safeDuration));
        } else if (event.key === 'ArrowLeft') {
            onSeek(clamp(position - KEYBOARD_STEP_MS, 0, safeDuration));
        }
    };

    return (
        <div className={className}>
            <div
                // slider si on peut agir dessus, simple progressbar sinon : le rôle annoncé correspond au comportement.
                role={interactive ? 'slider' : 'progressbar'}
                aria-label="Progression du morceau"
                aria-valuemin={0}
                aria-valuemax={Math.round(safeDuration / 1000)}
                aria-valuenow={Math.round(position / 1000)}
                aria-valuetext={`${formatDuration(position)} sur ${formatDuration(safeDuration)}`}
                tabIndex={interactive ? 0 : undefined}
                onClick={handleClick}
                onKeyDown={handleKeyDown}
                className={`group relative h-2 w-full overflow-hidden rounded-full bg-white/10 ${interactive ? 'cursor-pointer hover:h-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent' : ''} transition-[height]`}
            >
                {/* transform plutôt que width : animé par le compositeur, sans recalcul de mise en page à chaque image. */}
                <div
                    data-testid="progress-fill"
                    className="h-full w-full origin-left rounded-full bg-accent"
                    style={{ transform: `scaleX(${ratio})` }}
                />
            </div>

            {showTimes && (
                <div className="tabular mt-1.5 flex justify-between text-xs text-slate-400">
                    <span>{formatDuration(position)}</span>
                    <span>{formatDuration(safeDuration)}</span>
                </div>
            )}
        </div>
    );
}
