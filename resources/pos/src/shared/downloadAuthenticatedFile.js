import apiConfig from "../config/apiConfig";

/**
 * Descarga o abre un archivo protegido enviando el token y el contexto
 * tenant mediante la instancia Axios habitual. Un enlace <a> directo no
 * puede adjuntar el Bearer token almacenado por el POS.
 */
export const downloadAuthenticatedFile = async (path, options = {}) => {
    const { open = false, filename = "documento" } = options;
    const pendingWindow = open ? window.open("", "_blank", "noopener,noreferrer") : null;

    try {
        const endpoint = path.replace(/^\/api\//, "").replace(/^\//, "");
        const response = await apiConfig.get(endpoint, { responseType: "blob" });
        const objectUrl = URL.createObjectURL(response.data);

        if (open && pendingWindow) {
            pendingWindow.location.href = objectUrl;
        } else {
            const link = document.createElement("a");
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
        }

        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
    } catch (error) {
        pendingWindow?.close();
        throw error;
    }
};
