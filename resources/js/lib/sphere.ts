/**
 * The single place where spherical coordinates change units.
 *
 * The backend stores and returns DEGREES: yaw in [-180, 180], pitch in
 * [-90, 90]. Photo Sphere Viewer works in RADIANS. Convert only at the
 * viewer boundary, using these helpers, for creation, editing and rendering.
 */

export type SphericalDegrees = { yaw: number; pitch: number };
export type SphericalRadians = { yaw: number; pitch: number };

const DEG_PER_RAD = 180 / Math.PI;

/**
 * Normalise any yaw in degrees to (-180, 180].
 */
export function normalizeYawDegrees(yaw: number): number {
    let value = ((yaw % 360) + 360) % 360;

    if (value > 180) {
        value -= 360;
    }

    return value;
}

export function clampPitchDegrees(pitch: number): number {
    return Math.max(-90, Math.min(90, pitch));
}

function round(value: number, decimals = 3): number {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
}

export function toRadians(position: SphericalDegrees): SphericalRadians {
    return {
        yaw: normalizeYawDegrees(position.yaw) / DEG_PER_RAD,
        pitch: clampPitchDegrees(position.pitch) / DEG_PER_RAD,
    };
}

export function toDegrees(position: SphericalRadians): SphericalDegrees {
    return {
        yaw: round(normalizeYawDegrees(position.yaw * DEG_PER_RAD)),
        pitch: round(clampPitchDegrees(position.pitch * DEG_PER_RAD)),
    };
}
