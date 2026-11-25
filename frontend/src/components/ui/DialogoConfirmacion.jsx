import { useRef } from 'react'
import ModalFlotante from '../cliente/ModalFlotante'
import Boton from './Boton'

/** El disparador captura el registro, los datos y la acción antes de abrir el diálogo. */
export default function DialogoConfirmacion({ titulo, mensaje, accion, peligro = false, alConfirmar, alCerrar }) {
  const confirmado = useRef(false)
  const confirmar = () => {
    if (confirmado.current) return
    confirmado.current = true
    alCerrar()
    alConfirmar()
  }

  return (
    <ModalFlotante titulo={titulo} alCerrar={alCerrar} ancho="angosto">
      <p>{mensaje}</p>
      <div className="modal-flotante__acciones">
        <Boton variante="secundario" data-foco-inicial onClick={alCerrar}>Volver sin cambios</Boton>
        <Boton variante={peligro ? 'peligro' : 'primario'} onClick={confirmar}>{accion}</Boton>
      </div>
    </ModalFlotante>
  )
}
