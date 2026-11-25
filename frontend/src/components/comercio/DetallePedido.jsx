import { Link } from 'react-router-dom'
import LineasImporte from './LineasImporte'
import EstadoCuentaPedido from './EstadoCuentaPedido'
import Insignia from '../ui/Insignia'
import { ESTADOS_ENTREGA, ESTADOS_PAGO, ESTADOS_PEDIDO, METODOS_PAGO, MODALIDADES, tonoEstado } from '../../utils/etiquetas'
import { formatearFechaHora, formatearMoneda } from '../../utils/formato'
import './DetallePedido.css'

/** Contenido de un pedido (líneas, entrega, línea de tiempo y pagos), solo lectura. */
export default function DetallePedido({ pedido, enlazarProductos = false, enlazarFactura = false }) {
  return (
    <div className="detalle-pedido">
      <div className="detalle-pedido__tarjeta entrada">
        <div className="detalle-pedido__cuerpo">
          <section className="detalle-pedido__seccion">
            <h2 className="detalle-pedido__titulo">Productos</h2>
            <LineasImporte documento={pedido} enlazarProductos={enlazarProductos} />
          </section>

          <EstadoCuentaPedido pedido={pedido} />

          {pedido.entrega && (
            <section className="detalle-pedido__seccion">
              <h2 className="detalle-pedido__titulo">Entrega</h2>
              <dl className="detalle-pedido__datos">
                <dt>Modalidad</dt><dd>{MODALIDADES[pedido.entrega.modalidad]}</dd>
                <dt>Estado</dt><dd><Insignia tono={tonoEstado(pedido.entrega.estado)}>{ESTADOS_ENTREGA[pedido.entrega.estado]}</Insignia></dd>
                <dt>Recibe</dt><dd>{pedido.entrega.nombre_receptor} · {pedido.entrega.telefono_contacto}</dd>
                {pedido.entrega.direccion && (<><dt>Dirección</dt><dd>{pedido.entrega.direccion}</dd></>)}
                {pedido.entrega.referencia && (<><dt>Referencia</dt><dd>{pedido.entrega.referencia}</dd></>)}
                {pedido.observacion && (<><dt>Descripción del lugar</dt><dd>{pedido.observacion}</dd></>)}
                {pedido.entrega.responsable_entrega && (<><dt>Responsable del envío</dt><dd>{pedido.entrega.responsable_entrega}</dd></>)}
                {pedido.entrega.telefono_responsable && (<><dt>Teléfono del responsable</dt><dd><a href={`tel:${pedido.entrega.telefono_responsable}`}>{pedido.entrega.telefono_responsable}</a></dd></>)}
                {pedido.entrega.fecha_entrega && (<><dt>Entregado</dt><dd>{formatearFechaHora(pedido.entrega.fecha_entrega)}</dd></>)}
              </dl>
            </section>
          )}

          <section className="detalle-pedido__seccion">
            <h2 className="detalle-pedido__titulo">Línea de tiempo</h2>
            <ol className="detalle-pedido__historial">
              {pedido.historial.map((h, i) => (
                <li key={i}>
                  <span className="detalle-pedido__punto" aria-hidden="true" />
                  <div>
                    <strong>{ESTADOS_PEDIDO[h.estado_nuevo]}</strong>
                    <span className="detalle-pedido__fecha">{formatearFechaHora(h.fecha)}</span>
                    {h.comentario && <p>{h.comentario}</p>}
                  </div>
                </li>
              ))}
            </ol>
          </section>

          <section className="detalle-pedido__seccion">
            <h2 className="detalle-pedido__titulo">Pagos</h2>
            {pedido.pagos.length === 0 ? <p className="detalle-pedido__vacio">Todavía no hay pagos.</p> : (
              <ul className="detalle-pedido__pagos">
                {pedido.pagos.map((p) => (
                  <li key={p.id}>
                    <span>{METODOS_PAGO[p.metodo]} · {formatearMoneda(p.monto)}</span>
                    <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PAGO[p.estado]}</Insignia>
                    <span className="detalle-pedido__fecha">{formatearFechaHora(p.fecha)}</span>
                  </li>
                ))}
              </ul>
            )}
            {pedido.plan && <p className="detalle-pedido__vacio">Plan de {pedido.plan.num_cuotas} cuotas · saldo {formatearMoneda(pedido.plan.saldo)}</p>}
            {pedido.factura && <p className="detalle-pedido__vacio">{enlazarFactura
              ? <Link to={`/mis-facturas/${pedido.factura.id}`}>Factura {pedido.factura.numero}</Link>
              : <>Factura {pedido.factura.numero}</>} · {pedido.factura.estado} · emitida el {formatearFechaHora(pedido.factura.fecha_emision)}
              {pedido.factura.fecha_anulacion && <> · anulada el {formatearFechaHora(pedido.factura.fecha_anulacion)}</>}</p>}
          </section>
        </div>
      </div>
    </div>
  )
}
