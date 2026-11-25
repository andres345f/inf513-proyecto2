import Boton from '../ui/Boton'
import FormularioNuevaTarjeta from './FormularioNuevaTarjeta'
import ModalFlotante from './ModalFlotante'

/**
 * Modal reutilizable para agregar una tarjeta sin salir de la página:
 * se abre desde el hub de Mi cuenta y desde el paso de pago del checkout.
 * La página conserva su propia ruta (/metodos-pago) como alternativa.
 */
export default function ModalMetodoPago({ alCerrar, alGuardado }) {
  return (
    <ModalFlotante titulo="Agregar método de pago" alCerrar={alCerrar} ancho="mediano">
      <p>Usa esta tarjeta para tener el pago listo en tus próximos pedidos. Cuando termines, cierra esta ventana.</p>
      <FormularioNuevaTarjeta alGuardado={alGuardado} />
      <div className="modal-flotante__pie">
        <Boton variante="secundario" bloque onClick={alCerrar}>Cerrar</Boton>
      </div>
    </ModalFlotante>
  )
}
