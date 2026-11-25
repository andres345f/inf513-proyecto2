import PagosCliente from '../../components/cliente/PagosCliente'

/**
 * /planes-pago (permiso planes_pago.propios): cuotas y saldos.
 * La estructura compartida con /mis-pagos vive en components/cliente/PagosCliente.
 */
export default function PlanesPagoPagina() {
  return <PagosCliente seccion="cuotas" />
}
