import PagosCliente from '../../components/cliente/PagosCliente'

/**
 * /mis-pagos (permiso pagos.propios): historial de pagos.
 * La estructura compartida con /planes-pago vive en components/cliente/PagosCliente.
 */
export default function MisPagosPagina() {
  return <PagosCliente seccion="historial" />
}
