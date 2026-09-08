import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faDatabase,
    faDownload,
    faHardDrive,
    faShieldHalved,
} from '@fortawesome/free-solid-svg-icons';
import { downloadDatabaseBackup } from './backupApi';

export default function PlatformSettings({ setNotice }) {
    const [downloading, setDownloading] = useState(false);
    const downloadBackup = async () => {
        if (downloading) return;
        setDownloading(true);

        try {
            const filename = await downloadDatabaseBackup();
            setNotice({
                text: `Respaldo generado correctamente: ${filename}`,
            });
        } catch (error) {
            setNotice({
                type: 'error',
                text: error.backupMessage ||
                    error.response?.data?.message ||
                    'No se pudo generar el respaldo. Verifica la configuración del servidor.',
            });
        } finally {
            setDownloading(false);
        }
    };

    return <section className="sa-settings">
        <div className="sa-settings-hero sa-card">
            <div className="sa-settings-hero__icon">
                <FontAwesomeIcon icon={faHardDrive} />
            </div>
            <div>
                <span className="sa-eyebrow">ADMINISTRACIÓN DE PLATAFORMA</span>
                <h2>Configuración de EcuaPOS</h2>
                <p>Centraliza las herramientas que afectan a toda la plataforma SaaS.</p>
            </div>
        </div>

        <div className="sa-settings-grid">
            <article className="sa-card sa-backup-card">
                <header>
                    <span className="sa-icon sa-icon--blue">
                        <FontAwesomeIcon icon={faDatabase} />
                    </span>
                    <div>
                        <span className="sa-eyebrow">SEGURIDAD DE DATOS</span>
                        <h2>Respaldo de base de datos</h2>
                    </div>
                </header>

                <p className="sa-backup-card__description">
                    Genera una copia SQL completa y actual de la plataforma, incluyendo organizaciones,
                    usuarios, inventario, ventas y configuración.
                </p>

                <div className="sa-backup-note">
                    <FontAwesomeIcon icon={faShieldHalved} />
                    <div>
                        <strong>Archivo confidencial</strong>
                        <span>Solo el superadministrador puede generarlo. Guárdalo en una ubicación segura.</span>
                    </div>
                </div>

                <footer>
                    <div>
                        <strong>Generación bajo demanda</strong>
                        <small>El respaldo se prepara con los datos existentes al momento de la descarga.</small>
                    </div>
                    <button
                        type="button"
                        className="sa-btn sa-btn--primary sa-backup-button"
                        disabled={downloading}
                        onClick={downloadBackup}
                    >
                        <FontAwesomeIcon icon={faDownload} />
                        {downloading ? 'Generando respaldo…' : 'Descargar respaldo'}
                    </button>
                </footer>
            </article>
        </div>
    </section>;
}
