import React from 'react';
import {Button} from 'react-bootstrap-v5';
import useReadOnlyMode, {READ_ONLY_MESSAGE} from '../../hooks/useReadOnlyMode';

/**
 * Botón "Crear/Nuevo" de los listados. Es uno de los dos cuellos de
 * botella por donde pasa casi toda la escritura del POS (el otro es
 * ActionButton), así que deshabilitarlo acá cubre decenas de módulos sin
 * tocarlos uno por uno.
 */
const TableButton = ({ButtonValue, to}) => {
    const readOnly = useReadOnlyMode();

    return(
        <div className='text-end order-2 mb-2'>
            <Button
                type='button'
                variant='primary'
                href={readOnly ? undefined : to}
                disabled={readOnly}
                title={readOnly ? READ_ONLY_MESSAGE : undefined}
            >
                {ButtonValue}
            </Button>
        </div>
    )
}

export default TableButton;
