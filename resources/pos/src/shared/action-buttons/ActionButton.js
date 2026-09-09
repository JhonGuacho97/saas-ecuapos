import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faEye,
    faPenToSquare,
    faTrash,
    faKey
} from '@fortawesome/free-solid-svg-icons';
import { placeholderText } from '../sharedMethod';
import useReadOnlyMode, { READ_ONLY_MESSAGE } from '../../hooks/useReadOnlyMode';

const ActionButton = (props) => {
    const {
        goToEditProduct,
        item,
        onClickDeleteModel = true,
        onClickPassword = null,
        isDeleteMode = true,
        isEditMode = true,
        isPasswordMode = true,
        goToDetailScreen,
        isViewIcon = false
    } = props;

    const isAdmin = item.name === 'admin' || item.email === 'admin@ecua-pos.com';
    // Ver/consultar sigue disponible en modo consulta; editar, borrar y
    // cambiar contraseñas no. El servidor responde 402 igual, esto solo
    // evita que el usuario lo descubra a golpes.
    const readOnly = useReadOnlyMode();

    return (
        <>
            {isViewIcon && (
                <button
                    title={placeholderText('globally.view.tooltip.label')}
                    className='btn text-success px-2 fs-3 ps-0 border-0'
                    onClick={(e) => {
                        e.stopPropagation();
                        goToDetailScreen(item.id);
                    }}
                >
                    <FontAwesomeIcon icon={faEye} />
                </button>
            )}

            {!isAdmin && isPasswordMode && onClickPassword && (
                <button
                    title={readOnly ? READ_ONLY_MESSAGE : 'Cambiar contraseña'}
                    disabled={readOnly}
                    className='btn text-warning fs-3 border-0 px-xxl-2 px-1'
                    onClick={(e) => {
                        e.stopPropagation();
                        onClickPassword(item);
                    }}
                >
                    <FontAwesomeIcon icon={faKey} />
                </button>
            )}

            {!isAdmin && isEditMode && (
                <button
                    title={readOnly ? READ_ONLY_MESSAGE : placeholderText('globally.edit.tooltip.label')}
                    disabled={readOnly}
                    className='btn text-primary fs-3 border-0 px-xxl-2 px-1'
                    onClick={(e) => {
                        e.stopPropagation();
                        goToEditProduct(item);
                    }}
                >
                    <FontAwesomeIcon icon={faPenToSquare} />
                </button>
            )}

            {!isAdmin && isDeleteMode && (
                <button
                    title={readOnly ? READ_ONLY_MESSAGE : placeholderText('globally.delete.tooltip.label')}
                    disabled={readOnly}
                    className='btn px-2 pe-0 text-danger fs-3 border-0'
                    onClick={(e) => {
                        e.stopPropagation();
                        onClickDeleteModel(item);
                    }}
                >
                    <FontAwesomeIcon icon={faTrash} />
                </button>
            )}
        </>
    );
};

export default ActionButton;
