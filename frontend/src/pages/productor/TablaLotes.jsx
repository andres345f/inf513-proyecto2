import Boton from '../../components/ui/Boton'
import Insignia from '../../components/ui/Insignia'
import { cantidadConUnidad } from '../../utils/etiquetas'
import { formatearFecha, formatearMoneda } from '../../utils/formato'
import './Productor.css'

const ESTADO_LOTE = { DISPONIBLE: 'exito', AGOTADO: 'aviso', RETIRADO: 'error' }
const etiquetaEstado = (t) => ({ DISPONIBLE: 'Disponible', AGOTADO: 'Agotado', RETIRADO: 'Retirado' })[t] ?? t

/**
 * Tabla de lotes de una producción con sus acciones (editar oferta, retirar oferta).
 * Reutilizada por el detalle de producción; la edición/retiro los delega la página.
 */
export default function TablaLotes({ lotes, unidad, puedeGestionar, alEditar, alRetirar, ocupado = false }) {
  if (lotes.length === 0) return <p className="productor__ayuda">Esta producción todavía no tiene lotes.</p>

  return (
    <div className="productor__tabla-contenedor" role="region" tabIndex={0} aria-label="Lotes de la producción">
      <table className="productor__tabla">
        <thead>
          <tr>
            <th scope="col">Lote</th>
            <th scope="col">Elaborado</th>
            <th scope="col">Vence</th>
            <th scope="col">Producido</th>
            <th scope="col">Ofrecido</th>
            <th scope="col">Comprometido</th>
            <th scope="col">Precio</th>
            <th scope="col">Estado</th>
            {puedeGestionar && <th scope="col">Acciones</th>}
          </tr>
        </thead>
        <tbody>
          {lotes.map((l) => (
            <tr key={l.id}>
              <td>{l.codigo}</td>
              <td>{l.fecha_elaboracion ? formatearFecha(l.fecha_elaboracion) : '—'}</td>
              <td>{l.fecha_vencimiento ? formatearFecha(l.fecha_vencimiento) : '—'}</td>
              <td>{cantidadConUnidad(l.cantidad_producida, unidad)}</td>
              <td>{cantidadConUnidad(l.cantidad_ofrecida, unidad)}</td>
              <td>{cantidadConUnidad(l.comprometido, unidad)}</td>
              <td>{l.precio_ofrecido == null ? '—' : formatearMoneda(l.precio_ofrecido)}</td>
              <td><Insignia tono={ESTADO_LOTE[l.estado] || 'info'}>{etiquetaEstado(l.estado)}</Insignia></td>
              {puedeGestionar && (
                <td>
                  <div className="productor__acciones">
                    {alEditar && <Boton variante="fantasma" onClick={() => alEditar(l)} disabled={ocupado}>Editar</Boton>}
                    {alRetirar && l.puede_editar && l.estado === 'DISPONIBLE' && (
                      <Boton variante="peligro" onClick={() => alRetirar(l)} disabled={ocupado}>Retirar oferta</Boton>
                    )}
                  </div>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

