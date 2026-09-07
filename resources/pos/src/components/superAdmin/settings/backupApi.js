import apiConfig from '../../../config/apiConfig';

const filenameFromDisposition = disposition => {
    const encoded = disposition?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    if (encoded) return decodeURIComponent(encoded.replace(/["']/g, ''));

    return disposition?.match(/filename="?([^";]+)"?/i)?.[1] ||
        `ecuapos-backup-${new Date().toISOString().slice(0, 10)}.sql`;
};

export const downloadDatabaseBackup = async () => {
    try {
        const response = await apiConfig.get('super-admin/backup/download', {
            responseType: 'blob',
            skipGlobalErrorRedirect: true,
        });
        const filename = filenameFromDisposition(response.headers['content-disposition']);
        const objectUrl = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);

        return filename;
    } catch (error) {
        const data = error.response?.data;
        if (data instanceof Blob && data.type.includes('json')) {
            try {
                const payload = JSON.parse(await data.text());
                error.backupMessage = payload.message;
            } catch (_) {}
        }
        throw error;
    }
};
