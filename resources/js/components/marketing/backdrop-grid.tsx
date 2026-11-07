/**
 * A faint grid with a soft brand glow, faded out at the edges, used behind heroes.
 */
export default function BackdropGrid() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-x-0 -top-16 -z-10 h-[640px] overflow-hidden"
        >
            <div className="absolute inset-0 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_60%_55%_at_50%_0%,black,transparent)] bg-[size:56px_56px] opacity-70" />
            <div className="absolute top-0 left-1/2 h-[360px] w-[720px] -translate-x-1/2 rounded-full bg-brand/15 blur-[110px] dark:bg-brand/25" />
        </div>
    );
}
