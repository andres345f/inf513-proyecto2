import { useId, useRef, useState } from 'react'
import BotonImprimir from '../ui/BotonImprimir'
import { MarcaFederEnPapel } from '../ui/MarcaFeder'
import { formatearFechaHora, formatearMoneda } from '../../utils/formato'
import './EstadoCuentaPedido.css'

const conceptos = [
  ['original', 'Importe original, incluido el envío'],
  ['devoluciones_aprobadas', 'Devoluciones con efecto económico aprobado'],
  ['cobrado', 'Pagos efectivamente cobrados'],
  ['reembolsado', 'Reembolsos ejecutados'],
  ['reembolso_pendiente', 'Reembolsos pendientes'],
  ['reduccion_cuotas', 'Reducción aplicada a cuotas'],
  ['pendiente_pago', 'Pendiente de pago'],
  ['neto', 'Cobro neto después de reintegros'],
]

export default function EstadoCuentaPedido({ pedido }) {
  const [desglose, setDesglose] = useState(false)
  const idDesglose = useId()
  const referenciaPapel = useRef(null)
  const cuenta = pedido.estado_cuenta
  if (!cuenta) return null
  return (
    <section className="detalle-pedido__seccion estado-cuenta" aria-label="Estado de cuenta">
      <h2 className="detalle-pedido__titulo">
        {cuenta.liquidacion ? 'Resumen de liquidación' : 'Estado de cuenta'}
        <MarcaFederEnPapel referencia={referenciaPapel} />
      </h2>
      <p>Pedido {pedido.codigo}{pedido.factura && <> · Factura {pedido.factura.numero} ({pedido.factura.estado})</>}</p>
      <dl className="estado-cuenta__principales">
        {[['original', 'Total original'], ['cobrado', 'Cobrado'], ['pendiente_pago', 'Pendiente de pago']].map(([campo, titulo]) => (
          <div key={campo}><dt>{titulo}</dt><dd>{formatearMoneda(cuenta[campo])}</dd></div>
        ))}
      </dl>
      <button type="button" className="estado-cuenta__alternar no-imprimir" aria-controls={idDesglose} aria-expanded={desglose}
        onClick={() => setDesglose(!desglose)}>{desglose ? 'Ocultar detalle del cálculo' : 'Ver detalles'}</button>
      <dl id={idDesglose} className="estado-cuenta__detalle" data-abierto={desglose}>
        {conceptos.map(([campo, etiqueta]) => (
          <div key={campo}><dt>{etiqueta}</dt><dd>{formatearMoneda(cuenta[campo])}</dd></div>
        ))}
      </dl>
      {cuenta.liquidacion && !pedido.factura && <p>El ajuste de cuotas saldó la deuda. El importe cobrado fue inferior al original.</p>}
      {cuenta.devoluciones.length > 0 && <>
        <h3>Devoluciones aprobadas</h3>
        <ul>{cuenta.devoluciones.map((d) => <li key={d.id}>{d.codigo} · {d.estado} · importe reintegrable {formatearMoneda(d.importe_reembolsable)}</li>)}</ul>
      </>}
      {cuenta.reembolsos.length > 0 && <>
        <h3>Historial de reintegros</h3>
        <ul>{cuenta.reembolsos.map((r) => (
          <li key={r.id}>Reintegro #{r.id} · pago #{r.pago_id}
            {r.devolucion_id ? ` · devolución ${cuenta.devoluciones.find((d) => d.id === r.devolucion_id)?.codigo ?? '#' + r.devolucion_id}` : ' · cancelación del pedido'}
            {' · '}{formatearMoneda(r.monto)} · {r.estado} · {formatearFechaHora(r.fecha_ejecucion ?? r.fecha_solicitud)}
          </li>
        ))}</ul>
      </>}
      <BotonImprimir referenciaPapel={referenciaPapel} className="no-imprimir">Imprimir Pedido</BotonImprimir>
    </section>
  )
}
