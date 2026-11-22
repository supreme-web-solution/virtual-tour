import type { PanoDataProvider } from '@photo-sphere-viewer/core';

/**
 * A scene whose image is an ordinary photo, not a 360° panorama, is shown as a
 * partial panorama: it covers only the angle the photo really shows and the
 * viewer will not look past its edges (no black area around it).
 *
 * The angle is a guess from the shape: a typical 4:3 phone photo spans about
 * 90° across, and wider photos span proportionally more.
 */
export function flatPhotoPanoData(media: {
    is_equirectangular: boolean;
}): PanoDataProvider {
    return (image) => {
        const ratio = image.width / image.height;

        // A 2:1 image is a whole sphere: say so outright, so the viewer never
        // shrinks it to a partial panorama (black gaps) and ignores any
        // partial-view metadata embedded in the file.
        if (media.is_equirectangular || Math.abs(ratio - 2) <= 0.05) {
            return {
                fullWidth: image.width,
                fullHeight: image.height,
                croppedWidth: image.width,
                croppedHeight: image.height,
                croppedX: 0,
                croppedY: 0,
            };
        }

        const horizontalDegrees = Math.min(
            340,
            Math.max(60, (90 * ratio) / (4 / 3)),
        );
        const fullWidth = Math.round((image.width * 360) / horizontalDegrees);
        const fullHeight = Math.round(fullWidth / 2);

        return {
            fullWidth,
            fullHeight,
            croppedWidth: image.width,
            croppedHeight: image.height,
            croppedX: Math.round((fullWidth - image.width) / 2),
            croppedY: Math.round((fullHeight - image.height) / 2),
        };
    };
}
