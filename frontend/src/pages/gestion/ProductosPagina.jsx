import { useCallback, useState } from 'react'

import { useSearchParams } from 'react-router-dom'

import { Plus } from 'lucide-react'

import { catalogoGestionApi, cuerpoProducto } from '../../api/catalogoGestionApi'

import { productoresApi } from '../../api/productoresApi'

import FormularioProducto from '../../components/gestion/FormularioProducto'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { cantidadConUnidad } from '../../utils/etiquetas'

import { rutaPublica } from '../../utils/rutas'
import { formatearMoneda, formatearNumero } from '../../utils/formato'

import '../productor/Productor.css'

/** Mantenimiento de productos: precios, stock mínimo, peso por unidad y publicación. */

function IdentidadProducto({ producto }) {
  const [fotoFallida, setFotoFallida] = useState(null)
  const url = rutaPublica(producto.imagen_url)
  return <div className="producto-lista__identidad">
    {url && fotoFallida !== url && <img src={url} alt="" loading="lazy" className="producto-lista__miniatura" onError={() => setFotoFallida(url)} />}
    <span>{producto.nombre}<br /><small>{producto.presentacion}</small>
      {(!url || fotoFallida === url) && <><br /><small className="producto-lista__sin-foto">Foto no disponible</small></>}
    </span>
  </div>
}

const COLUMNAS = [
  { clave: 'codigo', etiqueta: 'Código' },
  { clave: 'nombre', etiqueta: 'Producto', alRender: (p) => <IdentidadProducto producto={p} /> },
  { clave: 'categoria', etiqueta: 'Categoría' },
  { clave: 'precio_venta', etiqueta: 'Precio', alRender: (p) => formatearMoneda(p.precio_venta) },
  {
    clave: 'stock', etiqueta: 'Stock',
    alRender: (p) => p.stock < p.stock_minimo
      ? <Insignia tono="error">{cantidadConUnidad(p.stock, p.unidad_medida)}</Insignia>
      : cantidadConUnidad(p.stock, p.unidad_medida),
  },
  { clave: 'peso_kg', etiqueta: 'Peso', alRender: (p) => (p.peso_kg != null ? `${formatearNumero(p.peso_kg)} kg` : '—') },
  { clave: 'estado', etiqueta: 'Estado', alRender: (p) => <Insignia tono={p.activo ? 'exito' : 'aviso'}>{p.activo ? 'A la venta' : 'Retirado'}</Insignia> },
]

export default function ProductosPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => catalogoGestionApi.productos(Object.fromEntries(parametros)), [clave])
  const categorias = useCargaApi(() => catalogoGestionApi.categorias(), [])
  const propuestas = useCargaApi(() => productoresApi.propuestasGestion(), [])
  const [edicion, setEdicion] = useState(null)
  const [propuestaActiva, setPropuestaActiva] = useState(null)
  const [motivoRechazo, setMotivoRechazo] = useState({})
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v !== '' && v != null ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const valor = (c) => parametros.get(c) ?? ''
  const alGuardar = (texto) => {
    setEdicion(null)
    setMensaje({ tipo: 'exito', texto })
    lista.recargar()
    propuestas.recargar()
    setPropuestaActiva(null)
  }

  const guardar = (datos) => propuestaActiva
    ? productoresApi.aprobarPropuesta(propuestaActiva, cuerpoProducto(datos))
    : (edicion?.producto ? catalogoGestionApi.actualizarProducto(edicion.producto.id, datos) : catalogoGestionApi.crearProducto(datos))

  const prepararPropuesta = (p) => {
    setPropuestaActiva(p.id)
    setEdicion({ producto: {
      categoria_id: '', codigo: '', nombre: p.nombre, descripcion: p.descripcion, presentacion: p.presentacion,
      unidad_medida: p.unidad_medida, precio_venta: '', stock_minimo: 0, peso_kg: '', permite_encargo: true, activo: true,
    } })
  }
  const rechazarPropuesta = async (p) => {
    try {
      const r = await productoresApi.rechazarPropuesta(p.id, motivoRechazo[p.id] || '')
      setMensaje({ tipo: 'exito', texto: r.message }); propuestas.recargar()
    } catch (falla) { setMensaje({ tipo: 'error', texto: falla.mensaje }) }
  }

  const filtros = (
    <>
      <label>Buscar<input defaultValue={valor('q')} maxLength={60} placeholder="Nombre o código"
        onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Categoría<select value={valor('categoria')} onChange={(e) => filtrar({ categoria: e.target.value })}>
        <option value="">Todas</option>
        {categorias.datos?.categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
      </select></label>
      <label>Estado<select value={valor('activo')} onChange={(e) => filtrar({ activo: e.target.value })}>
        <option value="">Todos</option><option value="1">A la venta</option><option value="0">Retirados</option>
      </select></label>
      <label>Stock<select value={valor('stock_bajo')} onChange={(e) => filtrar({ stock_bajo: e.target.value })}>
        <option value="">Todos</option><option value="1">Solo stock bajo</option>
      </select></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Productos"
      descripcion="Mantén precios, stock mínimo, peso por unidad y publicación de cada producto."
      accionPrincipal={!edicion && (
        <Boton onClick={() => { setPropuestaActiva(null); setEdicion({ producto: null }) }}>
          <Plus size={18} aria-hidden="true" /> Nuevo producto
        </Boton>
      )}
      etiquetaFiltros="Filtrar productos"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      actualizando={lista.actualizando}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} ${lista.datos.paginacion.total === 1 ? 'producto' : 'productos'}` : null}
      vacio={!edicion && lista.datos?.productos.length === 0 ? { titulo: 'No hay productos con esos filtros', mensaje: 'Prueba quitando algún filtro o crea un producto nuevo.' } : null}
      paginacion={lista.datos?.productos.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {edicion && categorias.datos && (
        <FormularioProducto key={propuestaActiva ? `propuesta-${propuestaActiva}` : (edicion.producto?.id ?? 'nuevo')} producto={edicion.producto} categorias={categorias.datos.categorias}
          guardar={guardar} alGuardar={alGuardar} alCancelar={() => { setEdicion(null); setPropuestaActiva(null) }} />
      )}

      {propuestas.datos?.propuestas?.some((p) => p.estado === 'PENDIENTE') && (
        <details className="productor__panel">
          <summary>Propuestas de productos pendientes</summary>
          {propuestas.datos.propuestas.filter((p) => p.estado === 'PENDIENTE').map((p) => (
            <div key={p.id} className="productor__panel">
              <strong>{p.nombre} · {p.presentacion}</strong><p>{p.descripcion}</p><small>Categoría sugerida: {p.categoria_sugerida} · {p.correo}</small>
              <div className="productor__acciones"><Boton onClick={() => prepararPropuesta(p)}>Completar y aprobar</Boton><input aria-label={`Motivo para rechazar ${p.nombre}`} placeholder="Motivo del rechazo" value={motivoRechazo[p.id] || ''} onChange={(e) => setMotivoRechazo((v) => ({ ...v, [p.id]: e.target.value }))} /><Boton variante="peligro" onClick={() => rechazarPropuesta(p)}>Rechazar</Boton></div>
            </div>
          ))}
        </details>
      )}

      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      <TablaDatos
        columnas={[...COLUMNAS, {
          clave: 'acciones', etiqueta: 'Acciones', claseCabecera: 'solo-lector',
          alRender: (p) => <Boton variante="secundario" aria-label={`Editar ${p.nombre}`} onClick={() => { setMensaje(null); setPropuestaActiva(null); setEdicion({ producto: p }) }}>Editar</Boton>,
        }]}
        filas={lista.datos?.productos ?? []}
      />
    </PaginaListado>
  )
}
