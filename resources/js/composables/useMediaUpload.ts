import { computed, ref } from 'vue';
import { postJson } from '@/lib/http';
import media from '@/routes/media';
import type { AssetType, AssetTypeOption, MediaAsset } from '@/types';

type UploadSignature = {
    upload_url: string;
    fields: Record<string, string | number>;
    chunk_size: number;
};

type CloudinaryResponse = {
    public_id: string;
    resource_type: 'image' | 'video';
    error?: { message: string };
};

export type UploadStatus =
    | 'queued'
    | 'uploading'
    | 'verifying'
    | 'done'
    | 'error';

export type UploadItem = {
    key: string;
    file: File;
    /** A local preview of the chosen image, shown before and during upload. */
    preview: string | null;
    status: UploadStatus;
    progress: number;
    error: string | null;
    asset: MediaAsset | null;
};

const CONCURRENCY = 3;

/**
 * Send one request to Cloudinary, reporting upload progress for the bytes in `body`.
 */
function sendToStorage(
    url: string,
    body: FormData,
    headers: Record<string, string>,
    onProgress: (loaded: number) => void,
): Promise<CloudinaryResponse> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', url);

        Object.entries(headers).forEach(([name, value]) =>
            request.setRequestHeader(name, value),
        );

        request.upload.onprogress = (event) => onProgress(event.loaded);
        request.onerror = () =>
            reject(new Error('Network error while uploading.'));
        request.onload = () => {
            let data: CloudinaryResponse | null = null;

            try {
                data = JSON.parse(request.responseText) as CloudinaryResponse;
            } catch {
                // Non-JSON response handled below.
            }

            if (request.status >= 200 && request.status < 300 && data) {
                resolve(data);
            } else {
                reject(
                    new Error(
                        data?.error?.message ??
                            `Upload failed (${request.status}).`,
                    ),
                );
            }
        };

        request.send(body);
    });
}

/**
 * Upload a file directly to storage, in chunks when it is large, so big
 * panoramas and videos never pass through the application server.
 */
async function uploadFile(
    file: File,
    signature: UploadSignature,
    onProgress: (fraction: number) => void,
): Promise<CloudinaryResponse> {
    const buildForm = (blob: Blob): FormData => {
        const form = new FormData();
        Object.entries(signature.fields).forEach(([key, value]) =>
            form.append(key, String(value)),
        );
        form.append('file', blob, file.name);

        return form;
    };

    if (file.size <= signature.chunk_size) {
        return sendToStorage(
            signature.upload_url,
            buildForm(file),
            {},
            (loaded) => onProgress(loaded / file.size),
        );
    }

    const uploadId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    let result: CloudinaryResponse | null = null;

    for (let start = 0; start < file.size; start += signature.chunk_size) {
        const end = Math.min(start + signature.chunk_size, file.size);

        result = await sendToStorage(
            signature.upload_url,
            buildForm(file.slice(start, end)),
            {
                'X-Unique-Upload-Id': uploadId,
                'Content-Range': `bytes ${start}-${end - 1}/${file.size}`,
            },
            (loaded) => onProgress((start + loaded) / file.size),
        );
    }

    return result as CloudinaryResponse;
}

function extensionOf(name: string): string {
    return name.split('.').pop()?.toLowerCase() ?? '';
}

export function useMediaUpload(assetTypes: AssetTypeOption[]) {
    const items = ref<UploadItem[]>([]);
    const running = ref(false);

    const finishedCount = computed(
        () => items.value.filter((item) => item.status === 'done').length,
    );
    const failedCount = computed(
        () => items.value.filter((item) => item.status === 'error').length,
    );

    function add(files: FileList | File[]): void {
        for (const file of Array.from(files)) {
            items.value.push({
                key: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
                file,
                preview: file.type.startsWith('image/')
                    ? URL.createObjectURL(file)
                    : null,
                status: 'queued',
                progress: 0,
                error: null,
                asset: null,
            });
        }
    }

    function release(list: UploadItem[]): void {
        list.forEach((item) => {
            if (item.preview) {
                URL.revokeObjectURL(item.preview);
            }
        });
    }

    function remove(key: string): void {
        release(items.value.filter((item) => item.key === key));
        items.value = items.value.filter((item) => item.key !== key);
    }

    function clear(): void {
        release(items.value);
        items.value = [];
    }

    /**
     * Client-side checks for fast feedback; the server re-validates everything.
     */
    function precheck(item: UploadItem, assetType: AssetType): string | null {
        const type = assetTypes.find((option) => option.value === assetType);

        if (!type) {
            return 'Choose what kind of media this is.';
        }

        const extension = extensionOf(item.file.name);

        if (!type.formats.includes(extension)) {
            return `.${extension || '?'} files aren't accepted for ${type.label.toLowerCase()}. Allowed: ${type.formats.join(', ')}.`;
        }

        if (item.file.size > type.max_bytes) {
            return `File is larger than the ${Math.round(type.max_bytes / 1024 / 1024)} MB limit.`;
        }

        return null;
    }

    async function processItem(
        item: UploadItem,
        signature: UploadSignature,
        assetType: AssetType,
        propertyId: string | null,
    ): Promise<void> {
        const problem = precheck(item, assetType);

        if (problem) {
            item.status = 'error';
            item.error = problem;

            return;
        }

        try {
            item.status = 'uploading';
            item.error = null;

            const stored = await uploadFile(
                item.file,
                signature,
                (fraction) => {
                    item.progress = Math.min(99, Math.round(fraction * 100));
                },
            );

            item.status = 'verifying';

            const { asset } = await postJson<{ asset: MediaAsset }>(
                media.store.url(),
                {
                    public_id: stored.public_id,
                    resource_type: stored.resource_type,
                    asset_type: assetType,
                    property_id: propertyId,
                    original_filename: item.file.name,
                },
            );

            item.asset = asset;
            item.progress = 100;
            item.status = 'done';
        } catch (error) {
            item.status = 'error';
            item.error =
                error instanceof Error ? error.message : 'Upload failed.';
        }
    }

    async function start(
        assetType: AssetType,
        propertyId: string | null,
    ): Promise<void> {
        const pending = items.value.filter(
            (item) => item.status === 'queued' || item.status === 'error',
        );

        if (!pending.length || running.value) {
            return;
        }

        running.value = true;

        try {
            const signature = await postJson<UploadSignature>(
                media.signature.url(),
                {},
            );

            const queue = [...pending];
            const workers = Array.from(
                { length: Math.min(CONCURRENCY, queue.length) },
                async () => {
                    let next = queue.shift();

                    while (next) {
                        await processItem(
                            next,
                            signature,
                            assetType,
                            propertyId,
                        );
                        next = queue.shift();
                    }
                },
            );

            await Promise.all(workers);
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Upload failed.';
            pending.forEach((item) => {
                item.status = 'error';
                item.error = message;
            });
        } finally {
            running.value = false;
        }
    }

    return {
        items,
        running,
        finishedCount,
        failedCount,
        add,
        remove,
        clear,
        start,
    };
}
