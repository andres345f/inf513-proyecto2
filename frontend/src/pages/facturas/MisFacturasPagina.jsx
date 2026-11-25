import { Link } from 'react-router-dom'

import { FileText, Receipt } from 'lucide-react'

import { facturasApi } from '../../api/facturasApi'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TarjetaItem from '../../components/plantillas/TarjetaItem'

import { useCargaApi } from '../../hooks/useCargaApi'

import { formatearFecha, formatearMoneda } from '../../utils/formato'

export default function MisFacturasPagina() {
  const lista = useCargaApi(() => facturasApi.listar(), [])

  return (
    <PaginaListado
      titulo="Mis facturas"
      descripcion="Se emiten cuando un pedido queda pagado por completo. Descárgalas o consúltalas aquí."
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      vacio={lista.datos?.facturas.length === 0 ? {
        titulo: 'Todavía no tienes facturas',
        mensaje: 'Se emiten automáticamente cuando un pedido queda pagado por completo.',
        icono: Receipt,
        accion: <Boton como={Link} to="/mis-pedidos">Ver mis pedidos</Boton>,
      } : null}
    >
      <div className="pagina-listado__lista">
        {lista.datos?.facturas.map((f) => (
          <TarjetaItem
            key={f.id}
            icono={FileText}
            titulo={f.numero}
            subtitulo={`Pedido ${f.pedido} · ${formatearFecha(f.fecha)}`}
            monto={formatearMoneda(f.total)}
            insignia={<Insignia tono={f.estado === 'VIGENTE' ? 'exito' : 'error'}>{f.estado === 'VIGENTE' ? 'Vigente' : 'Anulada'}</Insignia>}
            acciones={<Boton variante="secundario" como={Link} to={`/mis-facturas/${f.id}`}>Ver factura</Boton>}
          />
        ))}
      </div>
    </PaginaListado>
  )
}
