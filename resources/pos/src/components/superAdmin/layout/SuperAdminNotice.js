import React, { useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCircleCheck, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';

export default function SuperAdminNotice({ notice, onClose }) {
    useEffect(() => {
        if (!notice) return undefined;
        const timer = window.setTimeout(onClose, 4500);
        return () => window.clearTimeout(timer);
    }, [notice, onClose]);

    if (!notice) return null;

    return <div className={`sa-notice sa-notice--${notice.type || 'success'}`} role="status">
        <FontAwesomeIcon icon={notice.type === 'error' ? faTriangleExclamation : faCircleCheck} />
        <span>{notice.text}</span>
        <button type="button" onClick={onClose} aria-label="Cerrar aviso">×</button>
    </div>;
}
