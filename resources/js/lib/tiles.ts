import type { EquirectangularTilesPanorama } from '@photo-sphere-viewer/equirectangular-tiles-adapter';
import type { SceneMedia } from '@/types';

/**
 * Whether a tour's panoramas should be shown through the tiling viewer.
 *
 * Very large panoramas (wider than the biggest single image the viewer
 * loads) are cut into tiles that load as the visitor looks around. The tiling
 * viewer only takes tiled panoramas, so it is used only when every scene is a
 * true 360° panorama; ordinary photos keep the plain viewer.
 */
export function tourUsesTiles(media: SceneMedia[]): boolean {
    return (
        media.length > 0 &&
        media.every((item) => item.is_equirectangular) &&
        media.some((item) => item.tiles !== null)
    );
}

/**
 * The panorama definition the tiling viewer expects. `baseUrl` is the
 * ordinary single image, shown at once; the sharp tiles load over it. Scenes
 * without tiles (or with tiles switched off) show just the base image.
 */
export function tilePanorama(
    media: SceneMedia,
    baseUrl: string,
    withTiles: boolean,
): EquirectangularTilesPanorama {
    const tiles = withTiles ? media.tiles : null;

    if (!tiles) {
        return { baseUrl, width: 4096, cols: 4, rows: 2, tileUrl: () => null };
    }

    const colWidth = Math.floor(tiles.width / tiles.cols);
    const rowHeight = Math.floor(tiles.height / tiles.rows);

    return {
        baseUrl,
        width: tiles.width,
        cols: tiles.cols,
        rows: tiles.rows,
        tileUrl: (col, row) => {
            const x = col * colWidth;
            const y = row * rowHeight;
            const w = col === tiles.cols - 1 ? tiles.width - x : colWidth;
            const h = row === tiles.rows - 1 ? tiles.height - y : rowHeight;

            return tiles.url_template
                .replace('{x}', String(x))
                .replace('{y}', String(y))
                .replace('{w}', String(w))
                .replace('{h}', String(h));
        },
    };
}
